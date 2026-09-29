<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrRepurpose\Domain\ValueObject\CapabilityGrants;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\PdfLayoutExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfTextExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfVisionExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionService;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolverInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class SourceIngestionServiceTest extends TestCase
{
    private PdfVisionExtractor $vision;

    private CapabilityGrantResolverInterface $grantResolver;

    /** @var AbstractLogger&object{records: list<array{level: mixed, message: string, context: array<mixed>}>} */
    private AbstractLogger $logger;

    /**
     * Builds a service whose PDF text tier returns fixed per-page descriptors, and whose
     * vision/layout tiers return tagged strings so the dispatcher routing is observable.
     *
     * @param list<array{page:int,text:string,isSparse:bool}> $textPages
     */
    private function service(array $textPages, string $resolvedPath = '/abs/doc.pdf', ?CapabilityGrants $grants = null): SourceIngestionService
    {
        $text = new class ($textPages) extends PdfTextExtractor {
            /** @param list<array{page:int,text:string,isSparse:bool}> $pages */
            public function __construct(private readonly array $pages) {}

            public function extract(string $absPath): array
            {
                return $this->pages;
            }
        };

        $runner = new class implements PopplerRunnerInterface {
            public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string
            {
                return 'PNG';
            }

            public function extractLayout(string $absPdfPath, int $page): string
            {
                return 'LAYOUT-P' . $page;
            }
        };

        $vision = new class extends PdfVisionExtractor {
            public int $calls = 0;

            public function __construct() {}

            public function ocrPage(string $absPdfPath, int $page, int $beUser, int $dpi = 200): string
            {
                ++$this->calls;

                return 'VISION-P' . $page;
            }
        };
        $this->vision = $vision;

        $layout = new PdfLayoutExtractor($runner);

        $fetcher = new class extends WebPageFetcher {
            public function __construct() {}
        };

        // Only resolve() is replaced: release() is the real one, so the tests see
        // which paths get deleted.
        $resolver = new class ($resolvedPath) extends PdfFileResolver {
            public function __construct(private readonly string $path) {}

            public function resolve(array $jobRow): string
            {
                return $this->path;
            }
        };

        $grantResolver = new class ($grants ?? CapabilityGrants::all()) implements CapabilityGrantResolverInterface {
            /** @var list<int> */
            public array $askedFor = [];

            public function __construct(private readonly CapabilityGrants $grants) {}

            public function resolve(int $beUserUid): CapabilityGrants
            {
                $this->askedFor[] = $beUserUid;

                return $this->grants;
            }
        };
        $this->grantResolver = $grantResolver;

        $this->logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        return new SourceIngestionService($fetcher, $resolver, $text, $vision, $layout, $grantResolver, $this->logger);
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
            'uid'      => 6, 'source_type' => 'pdf_url', 'source_value' => 'https://example.com/x.pdf',
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

    public function testDeletesTheDownloadedPdfOnceItIsRead(): void
    {
        $download = sys_get_temp_dir() . '/nrrepurpose_dl_' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($download, '%PDF');

        $this->service([['page' => 1, 'text' => 'dense', 'isSparse' => false]], $download)
            ->ingest(['uid' => 10, 'source_type' => 'pdf_url', 'source_value' => 'https://example.com/x.pdf', 'pdf_mode' => 'text', 'be_user' => 0]);

        self::assertFileDoesNotExist($download);
    }

    public function testDeletesTheDownloadedPdfAlsoWhenNothingCouldBeRead(): void
    {
        $download = sys_get_temp_dir() . '/nrrepurpose_dl_' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($download, '%PDF');

        try {
            $this->service([['page' => 1, 'text' => '', 'isSparse' => false]], $download)
                ->ingest(['uid' => 11, 'source_type' => 'pdf_url', 'source_value' => 'https://example.com/x.pdf', 'pdf_mode' => 'text', 'be_user' => 0]);
            self::fail('An empty PDF must fail the ingestion');
        } catch (IngestionException) {
        }

        self::assertFileDoesNotExist($download);
    }

    public function testLeavesAnAttachedPdfInPlace(): void
    {
        // For the local FAL driver the resolved path is the editor's file itself.
        $dir = sys_get_temp_dir() . '/fileadmin_' . bin2hex(random_bytes(6));
        mkdir($dir);
        $attached = $dir . '/nrrepurpose_dl_report.pdf';
        file_put_contents($attached, '%PDF');

        try {
            $this->service([['page' => 1, 'text' => 'dense', 'isSparse' => false]], $attached)
                ->ingest(['uid' => 12, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'text', 'be_user' => 0]);

            self::assertFileExists($attached);
        } finally {
            unlink($attached);
            rmdir($dir);
        }
    }

    public function testForcedVisionModeWithoutTheVisionGrantReadsTheEmbeddedTextInstead(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => 'embedded one', 'isSparse' => false],
            ['page' => 2, 'text' => 'embedded two', 'isSparse' => false],
        ], grants: new CapabilityGrants(audio: true, vision: false));

        $doc = $service->ingest([
            'uid' => 13, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'vision', 'be_user' => 7,
        ]);

        self::assertSame("embedded one\n\nembedded two", $doc->text);
        self::assertSame(['text'], $doc->meta['tiersUsed']);
        self::assertTrue($doc->meta['visionDenied']);
        self::assertSame(0, $this->vision->calls);
        self::assertSame([7], $this->grantResolver->askedFor);
    }

    public function testAutoModeWithoutTheVisionGrantKeepsASparsePageOnTheTextTier(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => 'dense narrative text', 'isSparse' => false],
            ['page' => 2, 'text' => 'thin', 'isSparse' => true],
        ], grants: CapabilityGrants::none());

        $doc = $service->ingest([
            'uid' => 14, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'auto', 'be_user' => 7,
        ]);

        self::assertSame("dense narrative text\n\nthin", $doc->text);
        self::assertSame(0, $this->vision->calls);
    }

    public function testAScannedPdfWithoutTheVisionGrantFailsNamingThePermission(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => '', 'isSparse' => true],
        ], grants: CapabilityGrants::none());

        try {
            $service->ingest([
                'uid' => 15, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'auto', 'be_user' => 7,
            ]);
            self::fail('A scanned PDF without the grant has no text to read');
        } catch (IngestionException $e) {
            self::assertSame(1749379453, $e->getCode());
            self::assertStringContainsString('nrrepurpose:generate_vision', $e->getMessage());
        }

        self::assertSame(0, $this->vision->calls);
    }

    public function testTheGrantIsNotLookedUpForTheTextAndTablesModes(): void
    {
        foreach (['text', 'tables'] as $mode) {
            $this->service([['page' => 1, 'text' => 'dense', 'isSparse' => false]], grants: CapabilityGrants::none())
                ->ingest(['uid' => 16, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => $mode, 'be_user' => 7]);

            self::assertSame([], $this->grantResolver->askedFor, $mode);
        }
    }

    public function testAPdfWithoutTextFailsWithoutTheServerPathAndLogsIt(): void
    {
        $path    = '/var/www/html/fileadmin/user_upload/internal/board-minutes.pdf';
        $service = $this->service([['page' => 1, 'text' => '', 'isSparse' => false]], $path);

        try {
            $service->ingest(['uid' => 17, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'text', 'be_user' => 0]);
            self::fail('A PDF without text must fail');
        } catch (IngestionException $e) {
            self::assertSame(1749379452, $e->getCode());
            self::assertStringNotContainsString('/var/www', $e->getMessage());
            self::assertStringNotContainsString('board-minutes', $e->getMessage());
            $thrown = $e;
        }

        self::assertCount(1, $this->logger->records);
        self::assertSame($path, $this->logger->records[0]['context']['path']);
        self::assertSame(17, $this->logger->records[0]['context']['job']);
        self::assertSame($thrown, $this->logger->records[0]['context']['exception']);
    }

    public function testAFailureInsideAPdfTierIsLoggedWithThePathAndPassedOn(): void
    {
        $path    = '/var/www/html/fileadmin/report.pdf';
        $failure = new IngestionException('PDF could not be parsed', 1749379421);
        $text    = new class ($failure) extends PdfTextExtractor {
            public function __construct(private readonly IngestionException $failure) {}

            public function extract(string $absPath): array
            {
                throw $this->failure;
            }
        };
        $resolver = new class ($path) extends PdfFileResolver {
            public function __construct(private readonly string $path) {}

            public function resolve(array $jobRow): string
            {
                return $this->path;
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<array<mixed>> */
            public array $contexts = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };
        $service = new SourceIngestionService(
            new class extends WebPageFetcher {
                public function __construct() {}
            },
            $resolver,
            $text,
            new class extends PdfVisionExtractor {
                public function __construct() {}
            },
            new PdfLayoutExtractor(new class implements PopplerRunnerInterface {
                public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string
                {
                    return '';
                }

                public function extractLayout(string $absPdfPath, int $page): string
                {
                    return '';
                }
            }),
            $this->createStub(CapabilityGrantResolverInterface::class),
            $logger,
        );

        try {
            $service->ingest(['uid' => 18, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'text', 'be_user' => 0]);
            self::fail('The parse failure must reach the caller');
        } catch (IngestionException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([['job' => 18, 'path' => $path, 'exception' => $failure]], $logger->contexts);
    }
}
