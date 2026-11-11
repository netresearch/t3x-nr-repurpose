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
use Netresearch\NrRepurpose\Resource\PendingFileMetadata;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Functional\Fixtures\AltTextGeneratorStandInListener;
use ReflectionProperty;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * An image stored with its own alt text must not reach an alt-text generator that
 * calls a paid vision model on every new file, as the upload listener of
 * EXT:ai_filemetadata does. A stand-in registered under that listener's service id
 * records the calls it would make.
 *
 * The fixture extension is listed before nr_repurpose, so without the `before` of
 * ApplyPendingFileMetadata the stand-in would run first and see no alt text.
 */
final class JobFileStorageAltTextTest extends AbstractFunctionalTestCase
{
    private const string ALT_TEXT = 'Big News – Details inside';

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_repurpose/Tests/Functional/Fixtures/Extensions/nrrepurpose_ai_filemetadata_fixture',
        'netresearch/nr-repurpose',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        AltTextGeneratorStandInListener::$active = true;
    }

    protected function tearDown(): void
    {
        AltTextGeneratorStandInListener::reset();
        parent::tearDown();
    }

    public function testAnImageStoredWithAltTextCausesNoModelCallAndKeepsTheAltText(): void
    {
        $provenance = new AiProvenance('nr_repurpose 9.9.9', DigitalSourceType::CompositeWithTrainedAlgorithmicMedia);
        $file       = $this->get(JobFileStorage::class)->store($this->png(), 'story-slide-1.png', $provenance, self::ALT_TEXT);

        self::assertSame([], AltTextGeneratorStandInListener::$modelCalls, 'The alt-text generator must not be called for an image with an alt text.');
        self::assertSame(self::ALT_TEXT, $this->metadataRow($file->getUid())['alternative']);
        self::assertSame($provenance->describe(), $this->metadataRow($file->getUid())['description'], 'The AI-origin description is written as before.');
        self::assertSame(self::ALT_TEXT, $file->getMetaData()['alternative'], 'The returned File carries the alt text.');
    }

    /**
     * FAL sanitises the name inside addFile(): the alt text must be registered under the
     * name the file is indexed with, not the one passed in.
     */
    public function testAnImageWhoseNameFalSanitisesKeepsItsAltText(): void
    {
        $file = $this->get(JobFileStorage::class)->store($this->png(), 'my diagram.png', null, self::ALT_TEXT);

        self::assertStringNotContainsString(' ', $file->getName(), 'FAL sanitised the name.');
        self::assertSame([], AltTextGeneratorStandInListener::$modelCalls);
        self::assertSame(self::ALT_TEXT, $this->metadataRow($file->getUid())['alternative']);
    }

    /**
     * Control: the stand-in is wired and runs for a file without an alt text.
     */
    public function testAFileStoredWithoutAltTextStillReachesTheGenerator(): void
    {
        $file = $this->get(JobFileStorage::class)->store($this->png(), 'indexing-probe.png');

        self::assertSame([$file->getUid()], AltTextGeneratorStandInListener::$modelCalls);
        self::assertSame('', (string) $this->metadataRow($file->getUid())['alternative']);
    }

    public function testABlankAltTextIsNotWritten(): void
    {
        $file = $this->get(JobFileStorage::class)->store($this->png(), 'blank-alt.png', null, '   ');

        self::assertSame([$file->getUid()], AltTextGeneratorStandInListener::$modelCalls);
        self::assertSame('', (string) $this->metadataRow($file->getUid())['alternative']);
    }

    /**
     * The worker is long-running: an alt text whose file never got indexed (FAL refused
     * the write) must not stay behind in the shared PendingFileMetadata.
     */
    public function testAnAltTextIsDroppedWhenTheWriteFails(): void
    {
        $storage = $this->get(JobFileStorage::class);
        $refused = false;

        try {
            $storage->store('<?php echo 1;', 'refused.php', null, 'Never applied');
        } catch (Throwable) {
            // The upload check denies the extension before the file is indexed.
            $refused = true;
        }

        self::assertTrue($refused, 'FAL must refuse a PHP file.');
        $pending = (new ReflectionProperty(JobFileStorage::class, 'pendingMetadata'))->getValue($storage);
        self::assertInstanceOf(PendingFileMetadata::class, $pending);
        self::assertSame([], (new ReflectionProperty(PendingFileMetadata::class, 'pending'))->getValue($pending));
        self::assertSame([], AltTextGeneratorStandInListener::$modelCalls, 'Nothing was indexed.');
    }

    private function png(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/Image/chromium-render.png');
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataRow(int $fileUid): array
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->select(['alternative', 'description'], 'sys_file_metadata', ['file' => $fileUid])
            ->fetchAssociative();
        self::assertIsArray($row, 'The metadata record exists.');

        return $row;
    }
}
