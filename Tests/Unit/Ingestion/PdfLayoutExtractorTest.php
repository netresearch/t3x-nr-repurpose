<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrRepurpose\Ingestion\PdfLayoutExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;
use PHPUnit\Framework\TestCase;

final class PdfLayoutExtractorTest extends TestCase
{
    public function testDelegatesToRunnerLayoutExtraction(): void
    {
        $runner = new class implements PopplerRunnerInterface {
            public int $lastPage = 0;

            public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string
            {
                return '';
            }

            public function extractLayout(string $absPdfPath, int $page): string
            {
                $this->lastPage = $page;

                return "Region    Q1     Q2\nNorth       10     14";
            }
        };

        $text = (new PdfLayoutExtractor($runner))->extractPage('/abs/doc.pdf', 3);

        self::assertSame(3, $runner->lastPage);
        self::assertStringContainsString('Region    Q1', $text);
    }
}
