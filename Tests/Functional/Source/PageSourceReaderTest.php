<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Source;

use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceReport;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use Netresearch\NrRepurpose\Source\PageSourceLimits;
use Netresearch\NrRepurpose\Source\PageSourceReader;
use Netresearch\NrRepurpose\Source\PageTreeWalker;
use Netresearch\NrRepurpose\Source\SourceAccess;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\JobSnapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** The worker's side of a page source, with the frontend answered by a recording fetcher. */
#[CoversClass(PageSourceReader::class)]
final class PageSourceReaderTest extends AbstractFunctionalTestCase
{
    private const int ADMIN = 1;

    private const int EDITOR = 2;

    /** @var array<string, string|IngestionException> URL path => page text or failure; the rest is "Text of <path>" */
    private array $answers = [];

    /** @var list<string> */
    private array $fetched = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SourcePages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SourceUsers.csv');
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://www.example.org/');
    }

    #[Test]
    public function aPageIsReadThroughItsPublicUrl(): void
    {
        $document = $this->reader()->read(JobSnapshots::of(['source_type' => 'page', 'source_value' => '10', 'be_user' => self::ADMIN]));

        self::assertSame(['https://www.example.org/about'], $this->fetched);
        self::assertSame('Text of /about', $document->text);
        self::assertSame('Fetched title', $document->title);
        self::assertSame('https://www.example.org/about', $document->sourceLabel);
    }

    #[Test]
    public function aTreeBecomesOneDocumentWithAHeadingPerPage(): void
    {
        $document = $this->reader()->read(JobSnapshots::of(['source_type' => 'page', 'source_value' => '10', 'source_depth' => 1, 'be_user' => self::EDITOR]));

        self::assertSame("# About\n\nText of /about\n\n# Team\n\nText of /about/team", $document->text);
        self::assertSame('About', $document->title);
        self::assertSame(2, $this->report($document)->pagesUsed);
        self::assertSame(1, $this->report($document)->skippedNoAccess);
    }

    #[Test]
    public function aPageThatCannotBeFetchedIsCountedAndTheOthersAreRead(): void
    {
        $this->answers['/about/team'] = new IngestionException('URL returned HTTP 404', 1);

        $document = $this->reader()->read(JobSnapshots::of(['source_type' => 'page', 'source_value' => '10', 'source_depth' => 2, 'be_user' => self::EDITOR]));

        self::assertSame(2, $this->report($document)->pagesUsed);
        self::assertSame(1, $this->report($document)->pagesFailed);
        self::assertStringNotContainsString('# Team', $document->text);
    }

    #[Test]
    public function theJobFailsWhenNoPageGaveText(): void
    {
        $this->answers['/about'] = new IngestionException('URL returned HTTP 503', 1);

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379484);
        $this->expectExceptionMessage('None of the 1 selected pages could be read: URL returned HTTP 503');

        $this->reader()->read(JobSnapshots::of(['source_type' => 'page', 'source_value' => '10', 'be_user' => self::ADMIN]));
    }

    #[Test]
    public function theTextLimitEndsTheSelectionAtAPageBoundaryAndSaysSo(): void
    {
        $this->answers['/about']      = str_repeat('a', 60);
        $this->answers['/about/team'] = str_repeat('b', 60);

        $document = $this->reader(maxChars: 100)->read(JobSnapshots::of(['source_type' => 'page', 'source_value' => '10', 'source_depth' => 2, 'be_user' => self::EDITOR]));

        self::assertSame(str_repeat('a', 60), $document->text);
        $report = $this->report($document);
        self::assertTrue($report->cutByTextLimit);
        // Team went over the limit, People never got its turn.
        self::assertSame(2, $report->pagesLeftOut);
        self::assertSame(['https://www.example.org/about', 'https://www.example.org/about/team'], $this->fetched);
    }

    #[Test]
    public function aFirstPageLongerThanTheLimitIsCut(): void
    {
        $this->answers['/about'] = str_repeat('ä', 150);

        $document = $this->reader(maxChars: 100)->read(JobSnapshots::of(['source_type' => 'page', 'source_value' => '10', 'be_user' => self::ADMIN]));

        self::assertSame(str_repeat('ä', 100), $document->text);
        self::assertTrue($this->report($document)->cutByTextLimit);
        self::assertSame(0, $this->report($document)->pagesLeftOut);
    }

    #[Test]
    public function thePageLimitReachesTheReport(): void
    {
        $document = $this->reader(maxPages: 1)->read(JobSnapshots::of(['source_type' => 'page', 'source_value' => '10', 'source_depth' => 1, 'be_user' => self::EDITOR]));

        self::assertCount(1, $this->fetched);
        self::assertTrue($this->report($document)->cutByPageLimit);
        self::assertSame(1, $this->report($document)->maxPages);
    }

    /** The form accepted the page; the owner lost it before the worker got there. */
    #[Test]
    public function theOwnersAccessIsCheckedAgain(): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379482);

        $this->reader()->read(JobSnapshots::of(['source_type' => 'page', 'source_value' => '101', 'be_user' => self::EDITOR]));
    }

    #[Test]
    public function aJobWithoutAnOwnerReadsNoPage(): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379480);

        $this->reader()->read(JobSnapshots::of(['source_type' => 'page', 'source_value' => '10', 'be_user' => 0]));
    }

    #[Test]
    public function aPageDeletedSinceTheJobWasMadeFailsTheJob(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['deleted' => 1], ['uid' => 10]);

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379481);

        $this->reader()->read(JobSnapshots::of(['source_type' => 'page', 'source_value' => '10', 'be_user' => self::ADMIN]));
    }

    #[Test]
    public function aPageOutsideAnySiteHasNoUrlAndNoText(): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379484);
        $this->expectExceptionMessage('Page 2 has no public URL');

        $this->reader()->read(JobSnapshots::of(['source_type' => 'page', 'source_value' => '2', 'be_user' => self::ADMIN]));
    }

    #[Test]
    public function aSelectionWithoutAnOpenablePageFailsBeforeAnyFetch(): void
    {
        try {
            $this->reader()->read(JobSnapshots::of(['source_type' => 'page', 'source_value' => '12', 'be_user' => self::ADMIN]));
            self::fail('A hidden page must not be fetched');
        } catch (IngestionException $e) {
            self::assertSame(1749379483, $e->getCode());
        }

        self::assertSame([], $this->fetched);
    }

    private function report(SourceDocument $document): SourceReport
    {
        $report = $document->meta['sourceReport'] ?? null;
        self::assertInstanceOf(SourceReport::class, $report);

        return $report;
    }

    private function reader(int $maxPages = 25, int $maxChars = 200000): PageSourceReader
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(
            static fn (string $extension, string $path = ''): int => $path === 'maxSourcePages' ? $maxPages : $maxChars,
        );

        return new PageSourceReader(
            $this->get(PageTreeWalker::class),
            $this->get(SourceAccess::class),
            new PageSourceLimits($configuration),
            $this->fetcher(),
            $this->get(SiteFinder::class),
            new NullLogger(),
        );
    }

    private function fetcher(): WebPageFetcher
    {
        $answers = &$this->answers;
        $fetched = &$this->fetched;

        return new class ($answers, $fetched) extends WebPageFetcher {
            /**
             * @param array<string, string|IngestionException> $answers
             * @param list<string>                             $fetched
             */
            public function __construct(private array &$answers, private array &$fetched) {}

            public function fetchOwnPage(string $url): SourceDocument
            {
                $this->fetched[] = $url;
                $path            = (string) parse_url($url, PHP_URL_PATH);
                $answer          = $this->answers[$path] ?? 'Text of ' . $path;
                if ($answer instanceof IngestionException) {
                    throw $answer;
                }

                return new SourceDocument('Fetched title', $answer, $url, 0, 'en');
            }
        };
    }
}
