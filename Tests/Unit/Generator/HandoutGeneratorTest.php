<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrRepurpose\Generator\HandoutGenerator;
use Netresearch\NrRepurpose\Generator\Support\TextLabels;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Rendering\HtmlToPdfRendererInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Service\CallerSource;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\DocumentGeneratorDoubles;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\MapTextLabels;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\File;

final class HandoutGeneratorTest extends TextGeneratorTestCase
{
    use DocumentGeneratorDoubles;

    private HtmlToPdfRendererInterface $printer;

    private JobFileStorage $storage;

    /** @var list<array<string, mixed>> */
    private array $templateCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $file = $this->createStub(File::class);
        $file->method('getUid')->willReturn(42);
        $this->printer = $this->recordingPdfRenderer();
        $this->storage = $this->recordingFileStorage($file);
    }

    protected function generator(): HandoutGenerator
    {
        $calls = &$this->templateCalls;

        return new class ($this->jobs, $this->budget(), $this->logger(), $this->completion, $this->printer, $this->storage, new MapTextLabels(), $calls) extends HandoutGenerator {
            /** @param list<array<string, mixed>> $calls */
            public function __construct(JobProcessingRepository $jobs, BudgetServiceInterface $budget, LoggerInterface $logger, CompletionServiceInterface $completion, HtmlToPdfRendererInterface $printer, JobFileStorage $storage, TextLabels $labels, private array &$calls)
            {
                parent::__construct($jobs, $budget, $logger, $completion, $printer, $storage, $labels);
            }

            protected function renderDocumentHtml(string $theme, array $variables): string
            {
                $this->calls[] = ['theme' => $theme] + $variables;

                return '<html>handout</html>';
            }
        };
    }

    protected function validAnswer(): array
    {
        return [
            'title'    => 'Quarterly report',
            'lead'     => 'Revenue grew by 12 percent.',
            'sections' => [
                ['heading' => 'Revenue', 'paragraphs' => ['Exports drove the growth.', 'Margins held.']],
                ['heading' => 'Locations', 'paragraphs' => ['A branch opened in Leipzig.']],
            ],
            'keyFacts' => ['Revenue +12 %', 'New branch in Leipzig'],
        ];
    }

    protected function expectedType(): string
    {
        return 'handout';
    }

    protected function wantColumn(): string
    {
        return 'want_handout';
    }

    protected function expectedOperation(): string
    {
        return CallerSource::GENERATE_HANDOUT;
    }

    protected function expectedLabel(): string
    {
        return 'Handout';
    }

    protected function expectedSteps(): array
    {
        return ['Handout: writing text', 'Handout: rendering PDF'];
    }

    public function testStoresTheTextTheContentAndAnA4PdfWithTheKeyFactsHeadingInTheTextsLanguage(): void
    {
        self::assertTrue($this->generatorWithAnswer()->generate($this->context(language: 'de')));

        $row = $this->jobs->row();
        self::assertSame(
            "Quarterly report\n\nRevenue grew by 12 percent.\n\nRevenue\nExports drove the growth.\n\nMargins held.\n\n"
            . "Locations\nA branch opened in Leipzig.\n\n- Revenue +12 %\n- New branch in Leipzig",
            $row['script_text'],
        );
        self::assertSame(42, $row['file_uid']);
        self::assertSame(['keyFacts' => 'Das Wichtigste in Kürze'], $this->templateCalls[0]['labels']);
        self::assertSame(794, $this->printer->calls[0]['width']);
        self::assertSame('handout.pdf', $this->storage->stored[0]['fileName']);
    }

    public function testSectionsParagraphsAndFactsAreCapped(): void
    {
        $sections = [];
        foreach (range(1, 8) as $i) {
            $sections[] = ['heading' => 'S' . $i, 'paragraphs' => ['one', 'two', 'three']];
        }

        self::assertTrue($this->generatorWithAnswer([
            'title' => 'T', 'lead' => 'L', 'sections' => $sections, 'keyFacts' => array_fill(0, 9, 'fact'),
        ])->generate($this->context()));

        $content = $this->jobs->metadata()['content'];
        self::assertCount(HandoutGenerator::MAX_SECTIONS, $content['sections']);
        self::assertSame(['one', 'two'], $content['sections'][0]['paragraphs']);
        self::assertCount(HandoutGenerator::MAX_FACTS, $content['keyFacts']);
    }

    public function testAHandoutWithoutALeadFailsReadably(): void
    {
        self::assertFalse($this->generatorWithAnswer(['title' => 'T', 'lead' => ' ', 'sections' => [['heading' => 'H', 'paragraphs' => ['p']]], 'keyFacts' => []])->generate($this->context()));

        self::assertSame('Handout generation error: the handout has no title or no lead', $this->jobs->row()['error_message']);
    }
}
