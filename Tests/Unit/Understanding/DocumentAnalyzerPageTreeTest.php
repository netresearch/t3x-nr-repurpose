<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Understanding;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Service\ConfiguredCompletionService;
use Netresearch\NrRepurpose\Service\UnusableAnswerException;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\JobSnapshots;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Seventeen pages and about 40,000 characters: the page tree that failed. The fake stands in for
 * nr-llm and a reasoning model, whose thinking counts against the answer's token limit. What it
 * does at the limit was measured: at 1000 tokens the brief of this tree came back empty with
 * finish reason "length" in three of four calls, and nr-llm's json_decode('') then reports
 * "Syntax error". At 16000 tokens it came back in four of four, in about 1050 tokens.
 */
final class DocumentAnalyzerPageTreeTest extends TestCase
{
    /** The record's default: nr-llm stores 1000 unless somebody changes it. */
    private const int RECORD_LIMIT = 1000;

    /** What the measured brief of this tree takes, thinking included. */
    private const int BRIEF_TOKENS = 1100;

    private const int DECODE_FAILED = 2805117333;

    private const array BRIEF = ['title' => 'Extensions', 'summary' => 'All of them.', 'keyPoints' => ['One'], 'sections' => [], 'audience' => 'Editors', 'language' => 'en'];

    private const array SECTION = ['summary' => 'A section.', 'keyPoints' => ['A point']];

    /** @var list<array{limit: ?int, kind: string}> */
    private array $calls = [];

    private function pageTree(): SourceDocument
    {
        $sections = [];
        for ($page = 1; $page <= 17; ++$page) {
            $sections[] = '# Page ' . $page . "\n\n" . str_repeat('A sentence about one extension of this tree. ', match ($page) {
                16      => 165,
                17      => 287,
                default => 30,
            });
        }

        return new SourceDocument('Extensions', implode("\n\n", $sections), 'Extensions [101]', 0, 'en');
    }

    /** @param callable(string, ?int, int): array<string, mixed> $answer kind, limit of the call, attempt of that kind */
    private function analyzerOver(callable $answer): DocumentAnalyzer
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('hasAccessRestrictions')->willReturn(false);
        $configuration->method('getMaxTokens')->willReturn(self::RECORD_LIMIT);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        $attempts = [];
        $inner    = self::createStub(CompletionServiceInterface::class);
        $inner->method('completeJsonForConfiguration')->willReturnCallback(
            function (string $prompt, LlmConfiguration $used, ?ChatOptions $options) use ($answer, &$attempts): array {
                $limit           = $options?->getMaxTokens();
                $kind            = str_contains($options?->getSystemPrompt() ?? '', 'one section of a larger document') ? 'map' : 'brief';
                $this->calls[]   = ['limit' => $limit, 'kind' => $kind];
                $attempts[$kind] = ($attempts[$kind] ?? 0) + 1;

                return $answer($kind, $limit ?? $used->getMaxTokens(), $attempts[$kind]);
            },
        );

        return new DocumentAnalyzer(
            new ConfiguredCompletionService($inner, new ConfigurationResolver($repository), new NullLogger()),
            new NullLogger(),
        );
    }

    public function testATreeOfThatSizeGetsItsBriefFromARecordAtTheDefaultLimit(): void
    {
        $document = $this->pageTree();
        self::assertEqualsWithDelta(40000, mb_strlen($document->text), 1000, 'The size of the tree that failed');

        $brief = $this->analyzerOver(static function (string $kind, ?int $limit): array {
            if ($kind === 'map') {
                return self::SECTION;
            }

            // What nr-llm throws for the empty answer of a cut-off reasoning model.
            if ($limit < self::BRIEF_TOKENS) {
                throw new InvalidArgumentException('Failed to decode JSON response: Syntax error', self::DECODE_FAILED);
            }

            return self::BRIEF;
        })->analyze($document, JobSnapshots::of());

        self::assertSame('Extensions', $brief->title);
        self::assertCount(5, $this->calls, 'Four sections and one brief, without a retry');
    }

    public function testOneSectionWithAnUnusableAnswerDoesNotFailTheTree(): void
    {
        $brief = $this->analyzerOver(static function (string $kind, ?int $limit, int $attempt): array {
            if ($kind === 'map' && $attempt === 2) {
                throw new InvalidArgumentException('Failed to decode JSON response: Syntax error', self::DECODE_FAILED);
            }

            return $kind === 'map' ? self::SECTION : self::BRIEF;
        })->analyze($this->pageTree(), JobSnapshots::of());

        self::assertSame('Extensions', $brief->title);
        self::assertCount(6, $this->calls, 'Five calls and the one retry');
        self::assertSame($this->calls[1]['limit'] * 2, $this->calls[2]['limit'], 'The retry has twice the room');
    }

    public function testTwoUnusableAnswersEndTheJobWithoutTheProvidersWords(): void
    {
        $analyzer = $this->analyzerOver(static function (): array {
            throw new InvalidArgumentException('Failed to decode JSON response: secret model text', self::DECODE_FAILED);
        });

        try {
            $analyzer->analyze($this->pageTree(), JobSnapshots::of());
            self::fail('Two unusable answers must end the job');
        } catch (UnusableAnswerException $e) {
            self::assertStringNotContainsString('secret model text', $e->getMessage());
            self::assertCount(2, $this->calls, 'One retry, not more');
        }
    }
}
