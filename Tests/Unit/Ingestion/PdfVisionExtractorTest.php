<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use LogicException;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrRepurpose\Ingestion\PdfVisionExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;
use Netresearch\NrRepurpose\Service\CallerSource;
use PHPUnit\Framework\TestCase;

final class PdfVisionExtractorTest extends TestCase
{
    public function testOcrsRasterizedPageAndPassesDataUriToVision(): void
    {
        $runner = new class implements PopplerRunnerInterface {
            public string $lastPdf = '';

            public int $lastPage = 0;

            public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string
            {
                $this->lastPdf  = $absPdfPath;
                $this->lastPage = $page;

                return "\x89PNG\r\n\x1a\nFAKEPNGBYTES";
            }

            public function extractLayout(string $absPdfPath, int $page): string
            {
                return '';
            }
        };

        $vision = $this->vision('Net revenue rose to 48 million euro.');

        $extractor = new PdfVisionExtractor($runner, $vision);
        $text      = $extractor->ocrPage('/abs/doc.pdf', 2, beUser: 7);

        self::assertSame('/abs/doc.pdf', $runner->lastPdf);
        self::assertSame(2, $runner->lastPage);
        self::assertStringStartsWith('data:image/png;base64,', $vision->receivedImageUrl);
        self::assertSame(
            base64_encode("\x89PNG\r\n\x1a\nFAKEPNGBYTES"),
            substr($vision->receivedImageUrl, strlen('data:image/png;base64,')),
        );
        self::assertStringContainsString('48 million euro', $text);
    }

    public function testJoinsArrayVisionResult(): void
    {
        $vision = $this->vision(['line one', 'line two']);

        $text = (new PdfVisionExtractor($this->runner(), $vision))->ocrPage('/abs/doc.pdf', 1, beUser: 0);

        self::assertSame("line one\nline two", $text);
    }

    /**
     * The OCR call names this extension and its pipeline step, so nr-llm Analytics
     * can attribute the cost instead of listing it as "Unattributed".
     */
    public function testVisionCallNamesThisExtensionAndTheOcrOperation(): void
    {
        $vision = $this->vision('text');

        (new PdfVisionExtractor($this->runner(), $vision))->ocrPage('/abs/doc.pdf', 1, beUser: 7);

        self::assertInstanceOf(VisionOptions::class, $vision->receivedOptions);
        self::assertSame('nr_repurpose', $vision->receivedOptions->getCallerSourceExtension());
        self::assertSame(CallerSource::EXTRACT_PDF_VISION, $vision->receivedOptions->getCallerSourceOperation());
    }

    public function testCallerSourceDoesNotDisplaceTheBudgetAndTokenOptions(): void
    {
        $vision = $this->vision('text');

        (new PdfVisionExtractor($this->runner(), $vision))->ocrPage('/abs/doc.pdf', 1, beUser: 7);

        self::assertInstanceOf(VisionOptions::class, $vision->receivedOptions);
        self::assertSame(7, $vision->receivedOptions->getBeUserUid());
        self::assertSame(2000, $vision->receivedOptions->getMaxTokens());
    }

    private function runner(): PopplerRunnerInterface
    {
        return new class implements PopplerRunnerInterface {
            public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string
            {
                return 'PNG';
            }

            public function extractLayout(string $absPdfPath, int $page): string
            {
                return '';
            }
        };
    }

    /**
     * Records what the extractor hands to nr-llm and replays $result.
     *
     * @param string|list<string> $result
     */
    private function vision(string|array $result): VisionServiceInterface
    {
        return new class ($result) implements VisionServiceInterface {
            public string $receivedImageUrl = '';

            public string $receivedPrompt = '';

            public ?VisionOptions $receivedOptions = null;

            /** @param string|list<string> $result */
            public function __construct(private readonly string|array $result) {}

            public function generateAltText(string|array $imageUrl, ?VisionOptions $options = null): string
            {
                return '';
            }

            public function generateTitle(string|array $imageUrl, ?VisionOptions $options = null): string
            {
                return '';
            }

            public function generateDescription(string|array $imageUrl, ?VisionOptions $options = null): string
            {
                return '';
            }

            /**
             * @return string|list<string>
             */
            public function analyzeImage(string|array $imageUrl, string $customPrompt, ?VisionOptions $options = null): string|array
            {
                $this->receivedImageUrl = (string) $imageUrl;
                $this->receivedPrompt   = $customPrompt;
                $this->receivedOptions  = $options;

                return $this->result;
            }

            public function analyzeImageFull(string $imageUrl, string $prompt, ?VisionOptions $options = null): VisionResponse
            {
                throw new LogicException('not used in this test');
            }
        };
    }
}
