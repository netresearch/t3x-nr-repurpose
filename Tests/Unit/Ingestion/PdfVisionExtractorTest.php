<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrRepurpose\Ingestion\PdfVisionExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;
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
                $this->lastPdf = $absPdfPath;
                $this->lastPage = $page;

                return "\x89PNG\r\n\x1a\nFAKEPNGBYTES";
            }

            public function extractLayout(string $absPdfPath, int $page): string
            {
                return '';
            }
        };

        $vision = new class implements VisionServiceInterface {
            public string $receivedImageUrl = '';
            public string $receivedPrompt = '';

            public function generateAltText(string|array $imageUrl, ?VisionOptions $options = null): string|array { return ''; }
            public function generateTitle(string|array $imageUrl, ?VisionOptions $options = null): string|array { return ''; }
            public function generateDescription(string|array $imageUrl, ?VisionOptions $options = null): string|array { return ''; }

            public function analyzeImage(string|array $imageUrl, string $customPrompt, ?VisionOptions $options = null): string|array
            {
                $this->receivedImageUrl = (string) $imageUrl;
                $this->receivedPrompt = $customPrompt;

                return 'Net revenue rose to 48 million euro.';
            }

            public function analyzeImageFull(string $imageUrl, string $prompt, ?VisionOptions $options = null): VisionResponse
            {
                throw new \LogicException('not used in this test');
            }
        };

        $extractor = new PdfVisionExtractor($runner, $vision);
        $text = $extractor->ocrPage('/abs/doc.pdf', 2, beUser: 7);

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
        $runner = new class implements PopplerRunnerInterface {
            public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string { return 'PNG'; }
            public function extractLayout(string $absPdfPath, int $page): string { return ''; }
        };
        $vision = new class implements VisionServiceInterface {
            public function generateAltText(string|array $imageUrl, ?VisionOptions $options = null): string|array { return ''; }
            public function generateTitle(string|array $imageUrl, ?VisionOptions $options = null): string|array { return ''; }
            public function generateDescription(string|array $imageUrl, ?VisionOptions $options = null): string|array { return ''; }
            public function analyzeImage(string|array $imageUrl, string $customPrompt, ?VisionOptions $options = null): string|array
            {
                return ['line one', 'line two'];
            }
            public function analyzeImageFull(string $imageUrl, string $prompt, ?VisionOptions $options = null): VisionResponse
            {
                throw new \LogicException('not used in this test');
            }
        };

        $text = (new PdfVisionExtractor($runner, $vision))->ocrPage('/abs/doc.pdf', 1, beUser: 0);

        self::assertSame("line one\nline two", $text);
    }
}
