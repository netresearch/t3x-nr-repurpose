<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\SourceReview;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Throwable;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/** The automatic check is separate from editorial approval. Legacy installations start off. */
final readonly class SourceReviewPolicy
{
    public const string PROFILE = 'nr_repurpose.source_fidelity';

    public const int VERSION = 1;

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function mode(): string
    {
        $value = $this->setting('sourceReviewMode', 'off');

        return in_array($value, ['off', 'observe', 'enforce'], true) ? $value : 'enforce';
    }

    public function configuration(): ?string
    {
        $value = $this->setting('sourceReviewConfiguration', '');
        if (!is_string($value)) {
            return self::UNAVAILABLE_CONFIGURATION;
        }

        if (trim($value) !== '') {
            return trim($value);
        }

        try {
            $fallback = $this->extensionConfiguration->get(
                'nr_llm',
                'decision/configuration',
            );

            return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return null;
        } catch (Throwable) {
            return self::UNAVAILABLE_CONFIGURATION;
        }
    }

    public function minimumScore(): float
    {
        $value = $this->setting('sourceReviewMinimumScore', '0.8');

        return is_numeric($value) && (float) $value > 0.0 && (float) $value <= 1.0 ? (float) $value : 1.0;
    }

    public function dataClass(): ToolDataClass
    {
        $value = $this->setting('sourceReviewDataClass', 'editorContent');

        return is_string($value) ? ToolDataClass::tryFrom($value) ?? ToolDataClass::SECRET_ADJACENT : ToolDataClass::SECRET_ADJACENT;
    }

    /** @param array<string, mixed> $review */
    public function allowsBrief(array $review): bool
    {
        return !$this->enforced($review) || $this->passed($review);
    }

    /**
     * A previously enforced verdict cannot be bypassed by switching the setting off.
     *
     * @param array<string, mixed>      $review
     * @param array<string, mixed>|null $briefReview
     */
    public function allowsArtifact(
        array $review,
        string $candidate,
        ?array $briefReview,
    ): bool {
        if (!$this->enforced($review) && !$this->enforced($briefReview ?? [])) {
            return true;
        }

        return $this->passed($review) && $this->passed($briefReview ?? []) && ($review['candidateHash'] ?? '') === hash('sha256', $candidate) && ($review['sourceHash'] ?? '') === ($briefReview['sourceHash'] ?? null);
    }

    /** @param array<string, mixed> $review */
    private function enforced(array $review): bool
    {
        return $this->mode() === 'enforce' || ($review['mode'] ?? null) === 'enforce';
    }

    /** @param array<string, mixed> $review */
    private function passed(array $review): bool
    {
        $answers = $review['answers'] ?? null;
        if (!is_array($answers)) {
            return false;
        }

        foreach (['supported', 'qualifications'] as $key) {
            $score = $answers[$key] ?? null;
            if (!is_int($score) && !is_float($score) || !is_finite((float) $score) || $score < $this->minimumScore() || $score > 1.0) {
                return false;
            }
        }

        if (($review['configurationSelector'] ?? null) !== $this->configuration() || ($review['dataClass'] ?? null) !== $this->dataClass()->value || $this->configuration() !== null && ($review['configuration'] ?? null) !== $this->configuration()) {
            return false;
        }

        return ($review['status'] ?? null) === 'passed' && ($review['profile'] ?? null) === self::PROFILE && ($review['profileVersion'] ?? null) === self::VERSION && is_string($review['sourceHash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $review['sourceHash']) === 1;
    }

    private function setting(string $name, mixed $default): mixed
    {
        try {
            return $this->extensionConfiguration->get('nr_repurpose', $name);
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return $default;
        } catch (Throwable) {
            // A broken configuration reader is never an opt-out.
            return $name === 'sourceReviewConfiguration' ? self::UNAVAILABLE_CONFIGURATION : null;
        }
    }

    public const string UNAVAILABLE_CONFIGURATION = '__nr_repurpose_configuration_unavailable__';

    /** @param array<string, mixed> $row */
    public static function sourcePointerHash(array $row): string
    {
        $source = [];
        foreach (['source_type', 'source_value', 'source_url', 'site', 'pdf_mode'] as $field) {
            $value          = $row[$field] ?? '';
            $source[$field] = is_string($value) || is_int($value) ? (string) $value : '';
        }

        foreach (['source_pdf', 'source_depth'] as $field) {
            $value          = $row[$field] ?? 0;
            $source[$field] = is_numeric($value) ? (int) $value : 0;
        }

        $source['references'] = $row['_source_pdf_references'] ?? [];

        return hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $briefReview
     * @param array<string, mixed> $artifactReview
     */
    public function allowsSource(
        array $briefReview,
        string $currentFingerprint,
        array $artifactReview = [],
    ): bool {
        return !$this->enforced($briefReview) && !$this->enforced($artifactReview) || ($briefReview['sourcePointerHash'] ?? null) === $currentFingerprint;
    }
}
