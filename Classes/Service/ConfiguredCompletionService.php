<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\StructuredCompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ConfigurationIdentifier;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Psr\Log\LoggerInterface;

/**
 * Routes the extension's text completions to the "nr_repurpose_text" nr-llm
 * Configuration record, so text generation targets its own named
 * configuration exactly like image generation ("nr_repurpose_image") and
 * speech ("nr_repurpose_tts") do — provider, model, system prompt, budget and
 * cost attribution all steer from that one record.
 *
 * It calls nr-llm's CompletionService (nr-llm 0.22+ / ADR-077): each method
 * resolves the named configuration and dispatches through the matching
 * `*ForConfiguration()` entry point. Per-call budget metadata on the options
 * is preserved on the configuration path. It implements the extension's own
 * TextCompletionInterface, not nr-llm's interface; the structured answer, whose
 * return shape nr-llm 0.39 changed, is unwrapped here.
 *
 * Fail-soft: when the "nr_repurpose_text" record is not imported (or inactive,
 * or access-restricted in a user-less worker context) resolution falls back to
 * the instance-default configuration — the pre-0.22 behaviour — so an
 * unconfigured install keeps working. Wired into the generators through the
 * TextCompletionInterface alias in Configuration/Services.yaml.
 *
 * Every text completion in this extension passes through here, so this is also
 * where the caller identity (nr-llm ADR-177) is guaranteed: options that already
 * name a caller are passed through untouched — the operation belongs to the call
 * site, not here — and options that name none are stamped with the extension key,
 * so a new call site cannot land in the Analytics "Unattributed" bucket.
 *
 * It also guarantees the room for the answer. nr-llm stores a limit of 1000 output tokens on a
 * configuration unless somebody changes it, and a reasoning model counts its thinking against
 * that limit: an answer of 1050 tokens then comes back empty with finish reason "length", and
 * nr-llm reports "Failed to decode JSON response: Syntax error", from 0.43.2 on that the model
 * reached its output limit. So a limit below
 * MIN_OUTPUT_TOKENS is raised per call. nr-llm caps the value at the model's known output limit;
 * a limit of 0 on the record means the model's own maximum and stays.
 */
final class ConfiguredCompletionService implements TextCompletionInterface
{
    /** The nr-llm Configuration record (identifier) steering text generation. */
    public const string CONFIGURATION = 'nr_repurpose_text';

    /** The least room a text completion gets for thinking and answer together. */
    public const int MIN_OUTPUT_TOKENS = 16000;

    /**
     * nr-llm's codes for an answer that is no JSON (2805117333), no object (2805117334) or empty
     * at the output limit (2041730951, OutputBudgetExhaustedException since nr-llm 0.43.2).
     * The code and not the class: the check dates from when older versions of nr-llm were supported.
     */
    private const array UNUSABLE_ANSWER_CODES = [2805117333, 2805117334, 2041730951];

    /** Resolved configuration, memoized once per instance (null = fall back to default). */
    private ?LlmConfiguration $configuration = null;

    private bool $resolved = false;

    /** @var list<array{kind: string, message: string}> hints of the most recent call that returned */
    private array $lastHints = [];

    /**
     * @param CompletionServiceInterface $inner the real nr-llm completion service
     */
    public function __construct(
        private readonly CompletionServiceInterface $inner,
        private readonly ConfigurationResolver $configurationResolver,
        private readonly LoggerInterface $logger,
        private readonly GuardrailHintCapture $hintCapture = new GuardrailHintCapture(),
    ) {}

    public function lastGuardrailHints(): array
    {
        return $this->lastHints;
    }

    /**
     * @return array<string, mixed>
     */
    public function completeJson(string $prompt, ?ChatOptions $options = null): array
    {
        $this->lastHints = [];
        $configuration   = $this->resolveConfiguration();
        $options         = $this->withOutputBudget($this->withCallerIdentity($options), $configuration);

        try {
            return $this->json($prompt, $configuration, $options);
        } catch (InvalidArgumentException|ProviderException $e) {
            if (!$this->isUnusableAnswer($e)) {
                throw $e;
            }

            $this->logger->warning('The text model gave no JSON object, asking once more with twice the room', ['exception' => $e]);
        }

        try {
            return $this->json($prompt, $configuration, $this->withDoubledBudget($options, $configuration));
        } catch (InvalidArgumentException|ProviderException $e) {
            if (!$this->isUnusableAnswer($e)) {
                throw $e;
            }

            throw new UnusableAnswerException('The text model gave no usable answer', 1790400101, $e);
        }
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public function completeStructured(string $prompt, array $schema, ?ChatOptions $options = null): array
    {
        $this->lastHints = [];
        $configuration   = $this->resolveConfiguration();
        $options         = $this->withOutputBudget($this->withCallerIdentity($options), $configuration);

        return $this->structuredData($this->captured(fn (): mixed => $configuration instanceof LlmConfiguration
            ? $this->inner->completeStructuredForConfiguration($prompt, $configuration, $schema, $options)
            : $this->inner->completeStructured($prompt, $schema, $options)));
    }

    public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string
    {
        $this->lastHints = [];
        $configuration   = $this->resolveConfiguration();
        $options         = $this->withOutputBudget($this->withCallerIdentity($options), $configuration);

        return $this->captured(fn (): string => $configuration instanceof LlmConfiguration
            ? $this->inner->completeMarkdownForConfiguration($prompt, $configuration, $options)
            : $this->inner->completeMarkdown($prompt, $options));
    }

    /**
     * One attempt of a JSON completion. Its hints replace the last ones only when it
     * returns, so a discarded attempt of completeJson() leaves none behind.
     *
     * @return array<string, mixed>
     */
    private function json(string $prompt, ?LlmConfiguration $configuration, ChatOptions $options): array
    {
        return $this->captured(fn (): array => $configuration instanceof LlmConfiguration
            ? $this->inner->completeJsonForConfiguration($prompt, $configuration, $options)
            : $this->inner->completeJson($prompt, $options));
    }

    /**
     * Run one nr-llm call inside the guardrail-hint capture and keep its hints.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function captured(callable $call): mixed
    {
        $captured        = $this->hintCapture->capture($call);
        $this->lastHints = $captured['hints'];

        return $captured['result'];
    }

    private function isUnusableAnswer(InvalidArgumentException|ProviderException $e): bool
    {
        return in_array($e->getCode(), self::UNUSABLE_ANSWER_CODES, true);
    }

    /** Without a resolved record the instance default answers, and its limit is unknown here. */
    private function withOutputBudget(ChatOptions $options, ?LlmConfiguration $configuration): ChatOptions
    {
        if ($options->getMaxTokens() !== null) {
            return $options;
        }

        $limit = $configuration?->getMaxTokens();

        return $limit === 0 || $limit >= self::MIN_OUTPUT_TOKENS
            ? $options
            : $options->withMaxTokens(self::MIN_OUTPUT_TOKENS);
    }

    private function withDoubledBudget(ChatOptions $options, ?LlmConfiguration $configuration): ChatOptions
    {
        $limit = $options->getMaxTokens() ?? $configuration?->getMaxTokens() ?? 0;

        return $limit > 0 ? $options->withMaxTokens($limit * 2) : $options;
    }

    /**
     * The decoded answer of a structured completion. nr-llm 0.39 and later return
     * a StructuredCompletionResponse whose `data` it is (ADR-211); nr-llm up to
     * 0.38 returned the array itself, which this still accepts although the
     * required versions (^0.43.4 || ^0.44) no longer installs such a version.
     *
     * @return array<string, mixed>
     */
    private function structuredData(mixed $result): array
    {
        // One assignment for both versions: with nr-llm 0.38 the class is unknown, so
        // `data` reads as mixed and needs the same declared shape as the bare array.
        $data = $result instanceof StructuredCompletionResponse ? $result->data : $result;

        /** @var array<string, mixed> $data nr-llm < 0.39 declares this shape, 0.39 types `data` so */
        return $data;
    }

    /**
     * Guarantee the call names this extension (nr-llm ADR-177). A caller that
     * already set one keeps it, operation included — the operation identifies the
     * pipeline step and only the call site knows it. A caller that set none (or
     * passed no options at all) gets the bare extension key: attributed to
     * nr_repurpose with an empty operation, rather than "Unattributed".
     */
    private function withCallerIdentity(?ChatOptions $options): ChatOptions
    {
        $options ??= new ChatOptions();
        $extension = $options->getCallerSourceExtension();

        return $extension === null || $extension === ''
            ? $options->withCallerSource(CallerSource::EXTENSION)
            : $options;
    }

    /**
     * Resolve the "nr_repurpose_text" configuration once, or null to fall back
     * to the instance default. Any nr-llm resolution failure (not imported,
     * inactive, access-restricted) is a fail-soft fallback, not an error.
     */
    private function resolveConfiguration(): ?LlmConfiguration
    {
        if ($this->resolved) {
            return $this->configuration;
        }

        $this->resolved = true;

        try {
            // A ConfigurationIdentifier, not the bare string: nr-llm 0.35 narrowed
            // this parameter to the value object (#893, second step). A string
            // reaches it as a TypeError, which is NOT an NrLlmExceptionInterface
            // — so the fail-soft catch below would not have caught it and the
            // whole text pipeline would have died instead of falling back.
            $this->configuration = $this->configurationResolver->getActiveByIdentifier(
                new ConfigurationIdentifier(self::CONFIGURATION),
            );
        } catch (NrLlmExceptionInterface $e) {
            $this->logger->debug(
                'nr_repurpose: "{identifier}" configuration not resolvable ({reason}); text generation falls back to the instance-default configuration.',
                ['identifier' => self::CONFIGURATION, 'reason' => $e->getMessage()],
            );
            $this->configuration = null;
        }

        return $this->configuration;
    }
}
