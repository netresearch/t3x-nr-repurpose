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
 * an uploaded PDF. As a TCA `link` field that allows only the `url` link type,
 * an edit in the backend stores an http(s) URL as entered (trimmed), and stores
 * an empty value with a log entry for a page, e-mail, path or JavaScript link.
 *
 * The `url` link type is not a URL validator: TYPO3 also classes a host
 * without a scheme (`example.org/a.pdf`) and other schemes (`ftp:`, `file:`)
 * as `url` and stores them; the ingestion's HTTP request decides about those.
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
     * @return iterable<string, array{string, string, bool}>
     */
    public static function sourceValues(): iterable
    {
        yield 'webpage URL' => ['https://www.netresearch.de/', 'https://www.netresearch.de/', false];
        yield 'plain http URL' => ['http://example.org/page', 'http://example.org/page', false];
        yield 'PDF URL with query' => ['https://example.org/files/report.pdf?v=2', 'https://example.org/files/report.pdf?v=2', false];
        yield 'surrounding whitespace is trimmed' => ['  https://example.org/a.pdf  ', 'https://example.org/a.pdf', false];
        yield 'unencoded space is kept as entered' => ['https://example.org/a b.pdf', 'https://example.org/a b.pdf', false];
        yield 'empty, as for an uploaded PDF' => ['', '', false];
        yield 'plain text is refused' => ['not a link', '', true];
        yield 'a page link is refused' => ['t3://page?uid=1', '', true];
        yield 'a page uid is refused' => ['42', '', true];
        yield 'an e-mail link is refused' => ['mailto:editor@example.org', '', true];
        yield 'a relative path is refused' => ['fileadmin/report.pdf', '', true];
        yield 'a JavaScript link is refused' => ['javascript:alert(1)', '', true];
    }

    #[DataProvider('sourceValues')]
    public function testBackendEditStoresOnlyUrls(string $input, string $expected, bool $refused): void
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
        // An empty result is either an empty input or a refusal; only the log
        // tells the two apart.
        self::assertSame($refused, $dataHandler->errorLog !== [], implode("\n", $dataHandler->errorLog));
    }

    /**
     * The link browser's URL tab offers target, title, class and rel by
     * default. Whatever an editor enters there is appended to the stored value
     * as a typolink suffix (`https://… _blank - "Title"`), which DataHandler
     * accepts and the ingestion would then request as part of the URL.
     */
    public function testLinkBrowserOffersNoLinkAttributes(): void
    {
        self::assertSame(
            [],
            $GLOBALS['TCA']['tx_nrrepurpose_domain_model_job']['columns']['source_value']['config']['appearance']['allowedOptions'] ?? null,
        );
    }
}
