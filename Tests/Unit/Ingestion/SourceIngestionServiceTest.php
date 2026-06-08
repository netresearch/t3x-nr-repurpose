<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\PdfLayoutExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfTextExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfVisionExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionService;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use PHPUnit\Framework\TestCase;

final class SourceIngestionServiceTest extends TestCase
{
    /**
     * Builds a service whose PDF text tier returns fixed per-page descriptors, and whose
     * vision/layout tiers return tagged strings so the dispatcher routing is observable.
     *
     * @param list<array{page:int,text:string,isSparse:bool}> $textPages
     */
    private function service(array $textPages): SourceIngestionService
    {
        $text = new class($textPages) extends PdfTextExtractor {
            /** @param list<array{page:int,text:string,isSparse:bool}> $pages */
            public function __construct(private readonly array $pages) {}

            public function extract(string $absPath): array
            {
                return $this->pages;
            }
        };

        $runner = new class implements PopplerRunnerInterface {
            public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string { return 'PNG'; }
            public function extractLayout(string $absPdfPath, int $page): string { return 'LAYOUT-P' . $page; }
        };

        $vision = new class extends PdfVisionExtractor {
            public function __construct() {}

            public function ocrPage(string $absPdfPath, int $page, int $beUser, int $dpi = 200): string
            {
                return 'VISION-P' . $page;
            }
        };
        $layout = new PdfLayoutExtractor($runner);

        $fetcher = new class extends WebPageFetcher {
            public function __construct() {}
        };

        $resolver = new class extends PdfFileResolver {
            public function __construct() {}

            public function resolve(array $jobRow): string { return '/abs/doc.pdf'; }
        };

        return new SourceIngestionService($fetcher, $resolver, $text, $vision, $layout);
    }

    public function testAutoModeRoutesEachPageByDensityAndTabularity(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => 'Plenty of dense narrative text on this first page.', 'isSparse' => false],
            ['page' => 2, 'text' => '', 'isSparse' => true],
            ['page' => 3, 'text' => "Region   Q1   Q2\nNorth     10   14\nSouth     8    9", 'isSparse' => false],
        ]);

        $doc = $service->ingest([
            'uid' => 5, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'auto', 'be_user' => 0,
        ]);

        self::assertStringContainsString('dense narrative text', $doc->text);
        self::assertStringContainsString('VISION-P2', $doc->text);
        self::assertStringContainsString('LAYOUT-P3', $doc->text);
        self::assertSame(3, $doc->pageCount);
        self::assertSame(['text', 'vision', 'tables'], $doc->meta['tiersUsed']);
        self::assertSame('doc.pdf', $doc->sourceLabel);
    }

    public function testForcedVisionModeOcrsEveryPage(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => 'dense', 'isSparse' => false],
            ['page' => 2, 'text' => 'dense', 'isSparse' => false],
        ]);

        $doc = $service->ingest([
            'uid' => 6, 'source_type' => 'pdf_url', 'source_value' => 'https://example.com/x.pdf',
            'pdf_mode' => 'vision', 'be_user' => 0,
        ]);

        self::assertSame("VISION-P1\n\nVISION-P2", $doc->text);
        self::assertSame(['vision'], $doc->meta['tiersUsed']);
    }

    public function testForcedTablesModeUsesLayoutForEveryPage(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => 'dense', 'isSparse' => false],
        ]);

        $doc = $service->ingest([
            'uid' => 7, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'tables', 'be_user' => 0,
        ]);

        self::assertSame('LAYOUT-P1', $doc->text);
        self::assertSame(['tables'], $doc->meta['tiersUsed']);
    }

    public function testForcedTextModeKeepsEmbeddedTextEvenWhenSparse(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => 'thin', 'isSparse' => true],
        ]);

        $doc = $service->ingest([
            'uid' => 8, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'text', 'be_user' => 0,
        ]);

        self::assertSame('thin', $doc->text);
        self::assertSame(['text'], $doc->meta['tiersUsed']);
    }

    public function testThrowsWhenNoTextCouldBeExtracted(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => '', 'isSparse' => false],
        ]);

        $this->expectException(IngestionException::class);
        $service->ingest([
            'uid' => 9, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'text', 'be_user' => 0,
        ]);
    }
}
