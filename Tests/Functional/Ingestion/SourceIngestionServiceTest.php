<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Ingestion;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\HttpFactory;
use LogicException;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\PdfLayoutExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfTextExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfVisionExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\SymfonyProcessPopplerRunner;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionService;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolverInterface;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\JobSnapshots;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\QueuedHttpClient;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StaticHostResolver;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SourceIngestionServiceTest extends AbstractFunctionalTestCase
{
    private function fixturePdf(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Pdf/sample-text.pdf';
    }

    private function htmlClient(): ClientInterface
    {
        $html = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Web/article.html');

        return QueuedHttpClient::answering(200, $html)->client;
    }

    /** Fails loudly if the auto dispatcher escalates a dense text PDF to Vision. */
    private function explodingVision(): PdfVisionExtractor
    {
        return new class extends PdfVisionExtractor {
            public function __construct() {}

            public function ocrPage(string $absPdfPath, int $page, int $beUser, int $dpi = 200): string
            {
                throw new LogicException('Vision must not be called for a text PDF in auto mode');
            }
        };
    }

    private function service(ClientInterface $client, PdfVisionExtractor $vision): SourceIngestionService
    {
        $factory = new HttpFactory();
        $runner  = new SymfonyProcessPopplerRunner(new NullLogger());

        return new SourceIngestionService(
            new WebPageFetcher($client, $factory, StaticHostResolver::publicGuard()),
            new PdfFileResolver($this->get(FileRepository::class), $client, $factory, StaticHostResolver::publicGuard()),
            new PdfTextExtractor(),
            $vision,
            new PdfLayoutExtractor($runner),
            $this->get(CapabilityGrantResolverInterface::class),
            new NullLogger(),
        );
    }

    public function testIngestsStaticHtmlIntoSourceDocument(): void
    {
        $doc = $this->service($this->htmlClient(), $this->explodingVision())
            ->ingest(JobSnapshots::of(['uid' => 1, 'source_type' => 'url', 'source_value' => 'https://example.com/q1', 'be_user' => 0]));

        self::assertInstanceOf(SourceDocument::class, $doc);
        self::assertSame('Quarterly Results 2026', $doc->title);
        self::assertStringContainsString('Revenue grew by 12 percent', $doc->text);
        self::assertSame(0, $doc->pageCount);
        self::assertSame('static', $doc->meta['fetchedVia']);
    }

    public function testIngestsRealTextPdfFalSourceViaAutoTier1(): void
    {
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $folder = $storage->hasFolder('repurpose') ? $storage->getFolder('repurpose') : $storage->createFolder('repurpose');
        $file   = $storage->createFile('ingest-test.pdf', $folder);
        $file->setContents((string) file_get_contents($this->fixturePdf()));
        // A type=file field as DataHandler stores it: the column counts the
        // references, the file hangs off sys_file_reference.
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('tx_nrrepurpose_domain_model_job')->insert('tx_nrrepurpose_domain_model_job', [
            'uid' => 2, 'pid' => 0, 'source_type' => 'pdf_fal', 'source_pdf' => 1,
        ]);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid'         => 0,
            'uid_local'   => $file->getUid(),
            'uid_foreign' => 2,
            'tablenames'  => 'tx_nrrepurpose_domain_model_job',
            'fieldname'   => 'source_pdf',
        ]);

        $doc = $this->service($this->htmlClient(), $this->explodingVision())
            ->ingest(JobSnapshots::of([
                'uid'      => 2, 'source_type' => 'pdf_fal', 'source_pdf' => 1,
                'pdf_mode' => 'auto', 'be_user' => 0,
            ]));

        self::assertStringContainsString('Net revenue rose to 48 million euro', $doc->text);
        self::assertSame(1, $doc->pageCount);
        self::assertContains('text', $doc->meta['tiersUsed']);
        self::assertNotContains('vision', $doc->meta['tiersUsed']);
    }
}
