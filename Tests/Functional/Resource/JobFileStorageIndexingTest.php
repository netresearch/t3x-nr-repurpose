<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Resource;

use Netresearch\NrRepurpose\Exception\EmptyArtifactContentException;
use Netresearch\NrRepurpose\Provenance\AiProvenance;
use Netresearch\NrRepurpose\Provenance\DigitalSourceType;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Functional\Fixtures\ReadsFileOnMetaDataCreatedListener;
use RuntimeException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Issue #78: a listener of the metadata record FAL creates while indexing a new file
 * (an alt-text generator) must see the stored bytes, not an empty placeholder — an
 * empty file became the image_url "data:image/jpeg;base64,", which the vision API
 * rejected with "Invalid base64 image_url." and which failed every PNG artifact.
 */
final class JobFileStorageIndexingTest extends AbstractFunctionalTestCase
{
    private const string EMPTY_IMAGE_URL = 'data:image/jpeg;base64,';

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-repurpose',
        'typo3conf/ext/nr_repurpose/Tests/Functional/Fixtures/Extensions/nrrepurpose_failing_metadata_fixture',
    ];

    protected function tearDown(): void
    {
        ReadsFileOnMetaDataCreatedListener::reset();
        parent::tearDown();
    }

    public function testAMetadataCreatedListenerReadsTheStoredImage(): void
    {
        $png                                        = (string) file_get_contents(__DIR__ . '/../../Fixtures/Image/chromium-render.png');
        $provenance                                 = new AiProvenance('nr_repurpose 9.9.9', DigitalSourceType::CompositeWithTrainedAlgorithmicMedia);
        ReadsFileOnMetaDataCreatedListener::$record = true;

        $file = $this->get(JobFileStorage::class)->store($png, 'story-slide-1.png', $provenance);

        self::assertCount(1, ReadsFileOnMetaDataCreatedListener::$imageUrls, 'Indexing the new file creates its metadata record once.');
        self::assertNotSame(self::EMPTY_IMAGE_URL, ReadsFileOnMetaDataCreatedListener::$imageUrls[0], 'The listener saw an empty file.');
        self::assertSame(
            'data:image/jpeg;base64,' . base64_encode($file->getContents()),
            ReadsFileOnMetaDataCreatedListener::$imageUrls[0],
            'The listener must see exactly the stored bytes.',
        );
    }

    public function testAFailingMetadataCreatedListenerLeavesNoFileBehind(): void
    {
        $png                                      = (string) file_get_contents(__DIR__ . '/../../Fixtures/Image/chromium-render.png');
        ReadsFileOnMetaDataCreatedListener::$fail = true;
        $metadataRowsBefore                       = $this->metadataRowCount();

        try {
            $this->get(JobFileStorage::class)->store($png, 'indexing-probe.png');
            self::fail('The listener failure must reach the caller.');
        } catch (RuntimeException $e) {
            self::assertSame(1790000902, $e->getCode());
        }

        self::assertSame([], $this->storedFileUids('indexing-probe-'), 'No sys_file row may remain for the failed store.');
        self::assertSame($metadataRowsBefore, $this->metadataRowCount(), 'No sys_file_metadata row may remain for the failed store.');
        self::assertSame([], glob(Environment::getPublicPath() . '/fileadmin/*/indexing-probe-*') ?: [], 'No file may remain on disk.');
    }

    public function testEmptyContentIsRefusedAndNothingIsWritten(): void
    {
        try {
            $this->get(JobFileStorage::class)->store('', 'empty-probe.png');
            self::fail('Empty content must be refused.');
        } catch (EmptyArtifactContentException $e) {
            self::assertSame(1790000601, $e->getCode());
            self::assertStringContainsString('empty-probe.png', $e->getMessage());
        }

        self::assertSame([], $this->storedFileUids('empty-probe-'), 'No sys_file row may be written for empty content.');
        self::assertSame([], glob(Environment::getPublicPath() . '/fileadmin/*/empty-probe-*') ?: [], 'No file may be written for empty content.');
    }

    /**
     * @return list<int>
     */
    private function storedFileUids(string $namePrefix): array
    {
        $rows = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->select(['uid', 'identifier'], 'sys_file')
            ->fetchAllAssociative();

        $uids = [];
        foreach ($rows as $row) {
            if (str_contains((string) $row['identifier'], $namePrefix)) {
                $uids[] = (int) $row['uid'];
            }
        }

        return $uids;
    }

    private function metadataRowCount(): int
    {
        return (int) GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->count('*', 'sys_file_metadata', []);
    }
}
