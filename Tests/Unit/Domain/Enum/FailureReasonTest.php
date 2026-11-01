<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\Enum;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Netresearch\NrRepurpose\Domain\Enum\FailureReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailureReasonTest extends TestCase
{
    private const string LANGUAGE_DIR = __DIR__ . '/../../../../Resources/Private/Language/';

    /** @return array<string, array{0: string, 1: string}> */
    public static function textsOfEveryReason(): array
    {
        $texts = [];
        foreach (FailureReason::cases() as $reason) {
            foreach (FailureReason::PARTS as $part) {
                $texts[$reason->value . ' ' . $part] = [$reason->value, $part];
            }
        }

        return $texts;
    }

    /** @return array<string, array{source: string, target: string}> */
    private function units(string $file): array
    {
        $document = new DOMDocument();
        self::assertTrue($document->load(self::LANGUAGE_DIR . $file));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

        $units = [];
        foreach ($xpath->query('//x:trans-unit') ?: [] as $unit) {
            assert($unit instanceof DOMElement);
            $units[$unit->getAttribute('id')] = [
                'source' => (string) $xpath->evaluate('string(x:source)', $unit),
                'target' => (string) $xpath->evaluate('string(x:target)', $unit),
            ];
        }

        return $units;
    }

    #[DataProvider('textsOfEveryReason')]
    public function testEveryReasonHasEveryTextInBothLanguages(string $reason, string $part): void
    {
        $key = sprintf('failure.%s.%s', $reason, $part);

        self::assertNotSame('', trim($this->units('locallang.xlf')[$key]['source'] ?? ''), 'en ' . $key);
        self::assertNotSame('', trim($this->units('de.locallang.xlf')[$key]['target'] ?? ''), 'de ' . $key);
    }

    /** A rule of the editor's words: what the worker calls a step and a percentage stay out. */
    #[DataProvider('textsOfEveryReason')]
    public function testNoTextNamesAStepSlugOrAnException(string $reason, string $part): void
    {
        $key = sprintf('failure.%s.%s', $reason, $part);
        foreach ([$this->units('locallang.xlf')[$key]['source'] ?? '', $this->units('de.locallang.xlf')[$key]['target'] ?? ''] as $text) {
            self::assertDoesNotMatchRegularExpression('/\b(analyzing|ingesting|generating|Exception|\d+ ?%)\b/', $text, $key);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: FailureReason}> */
    public static function storedRows(): array
    {
        return [
            'a stored reason'             => ['answer_unusable', 'Analysis failed', FailureReason::AnswerUnusable],
            'the row of an older job'     => ['', 'Analysis failed', FailureReason::Unknown],
            'older, some formats'         => ['', '3 of 10 formats failed; each failed artifact shows its error', FailureReason::SomeFormatsFailed],
            'older, all formats'          => ['', 'All 3 formats failed; each artifact shows its error', FailureReason::AllFormatsFailed],
            'older, a provider message'   => ['', 'Provider answered 401 with key sk-secret', FailureReason::Unknown],
            'a reason of a later version' => ['from_the_future', 'Analysis failed', FailureReason::Unknown],
        ];
    }

    #[DataProvider('storedRows')]
    public function testAStoredRowGivesItsReason(string $stored, string $message, FailureReason $expected): void
    {
        self::assertSame($expected, FailureReason::fromStored($stored, $message));
    }

    public function testOnlyTheMessagesWrittenForTheEditorAreShown(): void
    {
        $shown = array_filter(FailureReason::cases(), static fn (FailureReason $reason): bool => $reason->isStoredMessageShown());

        self::assertSame([FailureReason::SourceUnreadable, FailureReason::SourceEmpty, FailureReason::SourceTooLong, FailureReason::Guardrail], array_values($shown));
    }

    /** Both directions: a run that cannot end differently is refused, a passing fault is offered. */
    public function testARetryIsOfferedWhereANewRunCanEndDifferently(): void
    {
        foreach ([FailureReason::SourceEmpty, FailureReason::SourceTooLong, FailureReason::Guardrail, FailureReason::SomeFormatsFailed, FailureReason::WorkerStalled] as $reason) {
            self::assertFalse($reason->isRetryable(), $reason->value);
        }

        foreach ([FailureReason::AnswerUnusable, FailureReason::ProviderTimeout, FailureReason::BudgetExhausted, FailureReason::Unknown, FailureReason::Internal] as $reason) {
            self::assertTrue($reason->isRetryable(), $reason->value);
        }
    }
}
