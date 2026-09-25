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
use Netresearch\NrRepurpose\Tests\Unit\Fixture\AiMarkerReader;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class JobFileStorageTest extends AbstractFunctionalTestCase
{
    private const GENERATOR = 'nr_repurpose 9.9.9';

    public function testStoreWritesContentAndReturnsResolvableFile(): void
    {
        $storage = $this->get(JobFileStorage::class);

        $file = $storage->store('hello world', 'unit-test.txt');

        self::assertGreaterThan(0, $file->getUid());
        self::assertSame('hello world', $file->getContents());
        self::assertStringContainsString('repurpose', $file->getIdentifier());
    }

    public function testWithAProvenanceThePngIsMarkedAndTheFileDescribedAsAiGenerated(): void
    {
        $png        = (string) file_get_contents(__DIR__ . '/../../Fixtures/Image/chromium-render.png');
        $provenance = new AiProvenance(self::GENERATOR, DigitalSourceType::TrainedAlgorithmicMedia, ['image' => 'image-model-x']);

        $file = $this->get(JobFileStorage::class)->store($png, 'schaubild-ki.png', $provenance);

        $xmp = AiMarkerReader::pngXmp($file->getContents());
        self::assertNotNull($xmp);
        self::assertSame(DigitalSourceType::TrainedAlgorithmicMedia->value, AiMarkerReader::xmpDigitalSourceType($xmp));
        self::assertSame($provenance->describe(), $this->storedDescription($file->getUid()));
    }

    public function testWithoutAProvenanceTheBytesAndTheMetadataStayUntouched(): void
    {
        $png = (string) file_get_contents(__DIR__ . '/../../Fixtures/Image/chromium-render.png');

        $file = $this->get(JobFileStorage::class)->store($png, 'plain.png');

        self::assertSame($png, $file->getContents());
        self::assertSame('', $this->storedDescription($file->getUid()));
    }

    public function testSubtitlesGetANoteBlockAndTheDescription(): void
    {
        $provenance = new AiProvenance(self::GENERATOR, DigitalSourceType::TrainedAlgorithmicMedia);

        $file = $this->get(JobFileStorage::class)->store("WEBVTT\n", 'podcast.vtt', $provenance);

        self::assertSame("WEBVTT\n\nNOTE " . $provenance->describe() . "\n", $file->getContents());
        self::assertSame($provenance->describe(), $this->storedDescription($file->getUid()));
    }

    public function testAnotherFileTypeIsDescribedButNotRewritten(): void
    {
        $provenance = new AiProvenance(self::GENERATOR, DigitalSourceType::TrainedAlgorithmicMedia);

        $file = $this->get(JobFileStorage::class)->store("plain\n", 'notes.txt', $provenance);

        self::assertSame("plain\n", $file->getContents());
        self::assertSame($provenance->describe(), $this->storedDescription($file->getUid()));
    }

    private function storedDescription(int $fileUid): string
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->select(['description'], 'sys_file_metadata', ['file' => $fileUid])
            ->fetchAssociative();

        return (string) ($row['description'] ?? '');
    }
}
