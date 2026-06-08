<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfTextExtractor;
use PHPUnit\Framework\TestCase;

final class PdfTextExtractorTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = __DIR__ . '/../../Fixtures/Pdf/sample-text.pdf';
    }

    public function testExtractsPerPageTextAndMarksDenseTextNotSparse(): void
    {
        $pages = (new PdfTextExtractor())->extract($this->fixture);

        self::assertCount(1, $pages);
        self::assertSame(1, $pages[0]['page']);
        self::assertStringContainsString('Net revenue rose to 48 million euro', $pages[0]['text']);
        self::assertFalse($pages[0]['isSparse']);
    }

    public function testThrowsIngestionExceptionForMissingFile(): void
    {
        $this->expectException(IngestionException::class);
        (new PdfTextExtractor())->extract('/no/such/file.pdf');
    }
}
