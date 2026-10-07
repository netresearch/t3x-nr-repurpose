<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrRepurpose\Generator\SlideDeckGenerator;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Provenance\AiProvenance;
use Netresearch\NrRepurpose\Provenance\DigitalSourceType;
use Netresearch\NrRepurpose\Rendering\HtmlToPdfRendererInterface;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Service\CallerSource;
use Netresearch\NrRepurpose\Service\TextCompletionInterface;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\ArtifactRecordingJobRepository;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\DocumentGeneratorDoubles;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

final class SlideDeckGeneratorTest extends TextGeneratorTestCase
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
        $file->method('getUid')->willReturn(99);
        $this->printer = $this->recordingPdfRenderer();
        $this->storage = $this->recordingFileStorage($file);
    }

    protected function generator(): SlideDeckGenerator
    {
        $calls = &$this->templateCalls;

        return new class ($this->jobs, $this->budget(), $this->logger(), $this->completion, $this->printer, $this->storage, $this->createStub(ViewFactoryInterface::class), $calls) extends SlideDeckGenerator {
            /** @param list<array<string, mixed>> $calls */
            public function __construct(JobProcessingRepository $jobs, BudgetServiceInterface $budget, LoggerInterface $logger, TextCompletionInterface $completion, HtmlToPdfRendererInterface $printer, JobFileStorage $storage, ViewFactoryInterface $viewFactory, private array &$calls)
            {
                parent::__construct($jobs, $budget, $logger, $completion, $printer, $storage, $viewFactory);
            }

            protected function renderDocumentHtml(string $theme, array $variables): string
            {
                $this->calls[] = ['theme' => $theme] + $variables;

                return '<html>deck</html>';
            }
        };
    }

    protected function validAnswer(): array
    {
        return [
            'title'    => 'Quarterly report',
            'subtitle' => 'Q3 at a glance',
            'slides'   => [
                ['heading' => 'Revenue', 'bullets' => ['Up 12 percent', 'Driven by exports']],
                ['heading' => 'Locations', 'bullets' => ['New branch in Leipzig']],
            ],
            'takeaway' => 'Growth continues.',
        ];
    }

    protected function expectedType(): string
    {
        return 'slide_deck';
    }

    protected function wantColumn(): string
    {
        return 'want_slide_deck';
    }

    protected function expectedOperation(): string
    {
        return CallerSource::GENERATE_SLIDE_DECK;
    }

    protected function expectedLabel(): string
    {
        return 'Slide deck';
    }

    protected function expectedSteps(): array
    {
        return ['Slide deck: writing text', 'Slide deck: rendering PDF'];
    }

    public function testStoresTheOutlineTheContentAndAPdfRenderedFromTheTemplate(): void
    {
        self::assertTrue($this->generatorWithAnswer()->generate($this->context()));

        $row = $this->jobs->row();
        self::assertSame(
            "Quarterly report\nQ3 at a glance\n\n1. Revenue\n- Up 12 percent\n- Driven by exports\n\n2. Locations\n- New branch in Leipzig\n\nGrowth continues.",
            $row['script_text'],
        );
        self::assertSame(99, $row['file_uid']);
        self::assertSame('<html>deck</html>', $row['source_html']);

        self::assertSame('nr', $this->templateCalls[0]['theme']);
        self::assertSame($this->validAnswer()['slides'], $this->templateCalls[0]['content']['slides']);
        self::assertSame('https://example.com/report', $this->templateCalls[0]['sourceLabel']);
        self::assertSame('de', $this->templateCalls[0]['language']);

        self::assertSame([['html' => '<html>deck</html>', 'width' => 1920]], $this->printer->calls);
        self::assertSame("%PDF-1.4\n%placeholder\n", $this->storage->stored[0]['content']);
        self::assertSame('slide-deck.pdf', $this->storage->stored[0]['fileName']);
        self::assertSame(DigitalSourceType::TrainedAlgorithmicMedia, $this->storage->stored[0]['provenance']?->sourceType);
    }

    public function testSlidesAreCappedAndIncompleteOnesDropped(): void
    {
        $slides = [['heading' => 'No bullets', 'bullets' => []], 'not an object'];
        foreach (range(1, 12) as $i) {
            $slides[] = ['heading' => str_repeat('H', 100), 'bullets' => array_fill(0, 7, str_repeat('b', 200))];
        }

        self::assertTrue($this->generatorWithAnswer(['title' => 'T', 'subtitle' => '', 'slides' => $slides, 'takeaway' => ''])->generate($this->context()));

        $content = $this->jobs->metadata()['content'];
        self::assertCount(SlideDeckGenerator::MAX_SLIDES - 2, $content['slides']);
        self::assertSame(SlideDeckGenerator::MAX_HEADING_CHARS, mb_strlen($content['slides'][0]['heading']));
        self::assertCount(SlideDeckGenerator::MAX_BULLETS, $content['slides'][0]['bullets']);
        self::assertSame(SlideDeckGenerator::MAX_BULLET_CHARS, mb_strlen($content['slides'][0]['bullets'][0]));
    }

    public function testADeckWithoutAUsableSlideFailsReadably(): void
    {
        self::assertFalse($this->generatorWithAnswer(['title' => 'T', 'subtitle' => '', 'slides' => [['heading' => 'H', 'bullets' => ['  ']]], 'takeaway' => ''])->generate($this->context()));

        self::assertSame('Slide deck generation error: the slide deck has no slide with a heading and bullet points', $this->jobs->row()['error_message']);
        self::assertSame([], $this->printer->calls);
    }

    public function testThePrintedTempFileIsRemovedWhetherStoringWorksOrNot(): void
    {
        self::assertTrue($this->generatorWithAnswer()->generate($this->context()));
        self::assertFileDoesNotExist($this->printer->written[0]);

        $this->storage = new class extends JobFileStorage {
            public function __construct() {}

            public function store(string $content, string $fileName, ?AiProvenance $provenance = null): File
            {
                throw RenderingException::because('Cannot AI-label the file: it is not a complete PDF', 1790000505);
            }
        };

        $this->jobs = new ArtifactRecordingJobRepository();
        self::assertFalse($this->generatorWithAnswer()->generate($this->context()));
        self::assertSame('Slide deck (default) file error: Cannot AI-label the file: it is not a complete PDF', $this->jobs->row()['error_message']);
        self::assertCount(2, $this->printer->written);
        self::assertFileDoesNotExist($this->printer->written[1]);
    }

    public function testAFailedPrintFailsTheRowAndStoresNothing(): void
    {
        $this->printer = new class implements HtmlToPdfRendererInterface {
            public function renderPdf(string $html, int $viewportWidth): string
            {
                throw RenderingException::because('HTML render failed (exit 1): no chromium', 1749400101);
            }
        };

        self::assertFalse($this->generatorWithAnswer()->generate($this->context()));

        $row = $this->jobs->row();
        self::assertSame('failed', $row['status']);
        self::assertSame('Slide deck (default) file error: HTML render failed (exit 1): no chromium', $row['error_message']);
        self::assertSame([], $this->storage->stored);
    }
}
