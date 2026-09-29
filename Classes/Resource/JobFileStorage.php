<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Resource;

use Netresearch\NrRepurpose\Exception\DefaultStorageUnavailableException;
use Netresearch\NrRepurpose\Exception\EmptyArtifactContentException;
use Netresearch\NrRepurpose\Provenance\AiContentMarker;
use Netresearch\NrRepurpose\Provenance\AiProvenance;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stores generated artifact bytes into the default FAL storage under a `repurpose/` folder
 * and returns the resulting sys_file (File). Artifacts reference it by sys_file uid.
 *
 * Given an AiProvenance, the file is AI-labelled on the way in (ADR-005): the marker is
 * embedded into PNG, MP3, WebVTT and PDF bytes (before the file is created, so a file that
 * cannot be labelled is never written), and the file's sys_file_metadata.description states
 * the AI origin for every file type. An MP4 is stored as it is: ffmpeg writes its marker
 * when it encodes the video. This is the last step after every re-encode, so
 * nothing downstream in the extension can strip the marker again.
 *
 * The bytes are in the file before FAL indexes it: the content goes through a temp file
 * into ResourceStorage::addFile(). Indexing creates the sys_file_metadata record and
 * dispatches AfterFileMetaDataCreatedEvent, and listeners of that event read the file —
 * an alt-text generator sends it to a vision model. Created empty and filled afterwards,
 * the file was still 0 bytes at that moment, and the vision call failed with
 * "Invalid base64 image_url." (issue #78). Empty content is refused outright.
 */
class JobFileStorage
{
    private const string SUBFOLDER = 'repurpose';

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly AiContentMarker $marker = new AiContentMarker(),
    ) {}

    public function store(string $content, string $fileName, ?AiProvenance $provenance = null): File
    {
        if ($content === '') {
            throw new EmptyArtifactContentException(
                sprintf('Refusing to store %s: the generated content is empty (0 bytes)', $fileName),
                1790000601,
            );
        }

        if ($provenance instanceof AiProvenance) {
            $content = $this->marker->mark($content, $fileName, $provenance);
        }

        $storage = $this->storageRepository->getDefaultStorage();
        if (!$storage instanceof ResourceStorage) {
            throw new DefaultStorageUnavailableException('No default FAL storage available', 1749379300);
        }

        // Artifacts are written by the async messenger worker, which runs in a CLI
        // context with no backend user. ResourceStorage would otherwise evaluate
        // backend-user file permissions and deny the write ("You are not allowed to
        // write to the target folder"). This is a trusted system write, so disable
        // permission evaluation for the write and restore it afterwards — the storage
        // instance is shared/cached, so leaving it mutated would affect later FAL ops.
        $previousEvaluatePermissions = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);

        try {
            $folder = $storage->hasFolder(self::SUBFOLDER)
                ? $storage->getFolder(self::SUBFOLDER)
                : $storage->createFolder(self::SUBFOLDER);

            // Unique target name to avoid collisions across runs.
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $unique    = pathinfo($fileName, PATHINFO_FILENAME)
                . '-' . bin2hex(random_bytes(4))
                . ($extension !== '' ? '.' . $extension : '');

            $file = $this->addWithContent($storage, $folder, $unique, $content);

            try {
                if ($provenance instanceof AiProvenance) {
                    // Core field (no custom column): the editor sees the AI origin in the
                    // file list's metadata and can still extend the text.
                    $metaData                = $file->getMetaData();
                    $metaData['description'] = $provenance->describe();
                    $metaData->save();
                }
            } catch (Throwable $e) {
                // The caller never learns this file's uid, so nothing else could
                // remove it: delete it here, then report the original failure.
                try {
                    $storage->deleteFile($file);
                } catch (Throwable) {
                    // Best effort; the original failure is what the caller needs.
                }

                throw $e;
            }

            return $file;
        } finally {
            $storage->setEvaluatePermissions($previousEvaluatePermissions);
        }
    }

    /**
     * Add $content as $name to $folder with the bytes already in place when FAL indexes
     * the file. A failure while indexing — a metadata listener that throws — leaves the
     * file and its sys_file row behind without handing the File to the caller; both are
     * removed before the failure is passed on.
     *
     * addFile() also runs FAL's upload check on extension and MIME type, which the former
     * createFile() + setContents() write never did: a stored artifact now passes the same
     * check as an uploaded file. ext_localconf.php declares "vtt" for it (the podcast
     * subtitles), which a fresh TYPO3 14 installation does not allow otherwise.
     */
    private function addWithContent(ResourceStorage $storage, Folder $folder, string $name, string $content): File
    {
        $tempPath = GeneralUtility::tempnam('nrrepurpose_');

        try {
            if (file_put_contents($tempPath, $content) !== strlen($content)) {
                throw new RuntimeException('Could not write the artifact to a temporary file for ' . $name, 1790000602);
            }

            try {
                // The name carries a random suffix, so CANCEL never fires in practice; it
                // keeps the identifier fixed for the cleanup below (RENAME could change it).
                // removeOriginal=false copies instead of renaming: the temp dir and the
                // storage can be different mounts, and a copy takes the storage's file mode.
                return $storage->addFile($tempPath, $folder, $name, DuplicationBehavior::CANCEL, false);
            } catch (Throwable $e) {
                $this->removeIndexedLeftover($storage, $folder->getIdentifier() . $name);

                throw $e;
            }
        } finally {
            if (is_file($tempPath)) {
                GeneralUtility::unlink_tempfile($tempPath);
            }
        }
    }

    /**
     * Delete a file that addFile() wrote and indexed before a listener failed. The indexer
     * writes the sys_file row before it creates the metadata record whose listeners can
     * fail, so getFileByIdentifier() reads that row here instead of indexing the file again.
     */
    private function removeIndexedLeftover(ResourceStorage $storage, string $identifier): void
    {
        try {
            if (!$storage->hasFile($identifier)) {
                return;
            }

            $file = $storage->getFileByIdentifier($identifier);
            if ($file instanceof File) {
                $storage->deleteFile($file);
            }
        } catch (Throwable) {
            // Best effort; the original failure is what the caller needs.
        }
    }
}
