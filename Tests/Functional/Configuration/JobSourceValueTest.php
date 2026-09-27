<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Configuration;

use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * `source_value` of a job holds the URL of a webpage or a PDF, and nothing for
 * an uploaded PDF. As a TCA `link` field restricted to URLs, an edit in the
 * backend stores an external URL as entered (trimmed) and refuses anything
 * else instead of storing text the ingestion would try to fetch.
 */
#[CoversNothing]
final class JobSourceValueTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AdminAndPage.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG']    = GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sourceValues(): iterable
    {
        yield 'webpage URL' => ['https://www.netresearch.de/', 'https://www.netresearch.de/'];
        yield 'PDF URL with query' => ['https://example.org/files/report.pdf?v=2', 'https://example.org/files/report.pdf?v=2'];
        yield 'surrounding whitespace is trimmed' => ['  https://example.org/a.pdf  ', 'https://example.org/a.pdf'];
        yield 'unencoded space is kept as entered' => ['https://example.org/a b.pdf', 'https://example.org/a b.pdf'];
        yield 'empty, as for an uploaded PDF' => ['', ''];
        yield 'plain text is refused' => ['not a link', ''];
        yield 'a page link is refused' => ['t3://page?uid=1', ''];
    }

    #[DataProvider('sourceValues')]
    public function testBackendEditStoresOnlyUrls(string $input, string $expected): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(
            ['tx_nrrepurpose_domain_model_job' => ['NEW1' => ['pid' => 1, 'source_type' => 'url', 'source_value' => $input]]],
            [],
        );
        $dataHandler->process_datamap();

        $uid = (int) ($dataHandler->substNEWwithIDs['NEW1'] ?? 0);
        self::assertGreaterThan(0, $uid, implode("\n", $dataHandler->errorLog));

        $stored = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->select(['source_value'], 'tx_nrrepurpose_domain_model_job', ['uid' => $uid])
            ->fetchOne();

        self::assertSame($expected, (string) $stored);
    }
}
