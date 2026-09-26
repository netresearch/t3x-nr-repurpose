<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Resource;

use Netresearch\NrRepurpose\Provenance\AiProvenance;
use Netresearch\NrRepurpose\Provenance\DigitalSourceType;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Functional\Fixtures\FailsOnMetaDataUpdateListener;
use RuntimeException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A store that fails after the file was written leaves no file behind: the
 * caller never learns its uid, so nothing else could remove it.
 */
final class JobFileStorageCleanupTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-repurpose',
        'typo3conf/ext/nr_repurpose/Tests/Functional/Fixtures/Extensions/nrrepurpose_failing_metadata_fixture',
    ];

    protected function tearDown(): void
    {
        FailsOnMetaDataUpdateListener::$fail = false;
        parent::tearDown();
    }

    public function testAFailedMetadataSaveRemovesTheStoredFile(): void
    {
        $provenance                          = new AiProvenance('nr_repurpose 9.9.9', DigitalSourceType::TrainedAlgorithmicMedia);
        FailsOnMetaDataUpdateListener::$fail = true;

        try {
            $this->get(JobFileStorage::class)->store("plain\n", 'cleanup-probe.txt', $provenance);
            self::fail('The metadata failure must reach the caller.');
        } catch (RuntimeException $e) {
            self::assertSame(1790000901, $e->getCode());
        }

        self::assertSame([], $this->storedIdentifiers(), 'No sys_file row may remain for the failed store.');
        self::assertSame([], glob(Environment::getPublicPath() . '/fileadmin/*/cleanup-probe-*') ?: [], 'No file may remain on disk.');
    }

    public function testWithoutAFailureTheFileStays(): void
    {
        $provenance = new AiProvenance('nr_repurpose 9.9.9', DigitalSourceType::TrainedAlgorithmicMedia);

        $file = $this->get(JobFileStorage::class)->store("plain\n", 'cleanup-probe.txt', $provenance);

        self::assertCount(1, $this->storedIdentifiers());
        self::assertSame("plain\n", $file->getContents());
    }

    /**
     * @return list<string>
     */
    private function storedIdentifiers(): array
    {
        $rows = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->select(['identifier'], 'sys_file')
            ->fetchFirstColumn();

        return array_values(array_filter(
            array_map(strval(...), $rows),
            static fn (string $identifier): bool => str_contains($identifier, 'cleanup-probe-'),
        ));
    }
}
