<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Resource;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Event\AfterFileMetaDataCreatedEvent;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Writes the metadata JobFileStorage registered in PendingFileMetadata into the
 * sys_file_metadata record FAL creates while it indexes a stored artifact.
 *
 * It runs before the upload listener of EXT:ai_filemetadata (marketing-factory), which
 * sends every new image without an alt text to a paid vision model. That listener skips a
 * record whose `alternative` is already set, so an image stored with its own alt text no
 * longer causes a model call, and the extension's batch command skips it as well. The
 * `before` names that listener's service id as a string: when the extension is not
 * installed, TYPO3's dependency ordering ignores an id no listener has, so nothing here
 * requires it.
 */
#[AsEventListener(
    identifier: 'nr-repurpose/apply-pending-file-metadata',
    before: 'Mfd\Ai\FileMetadata\EventListener\EnrichFileMetadataAfterCreation',
)]
final readonly class ApplyPendingFileMetadata
{
    public function __construct(
        private PendingFileMetadata $pending,
        private ResourceFactory $resourceFactory,
        private ConnectionPool $connectionPool,
    ) {}

    public function __invoke(AfterFileMetaDataCreatedEvent $event): void
    {
        $file   = $this->resourceFactory->getFileObject($event->getFileUid());
        $fields = $this->pending->take($file->getCombinedIdentifier());
        if ($fields === []) {
            return;
        }

        $this->connectionPool->getConnectionForTable('sys_file_metadata')
            ->update('sys_file_metadata', $fields, ['uid' => $event->getMetaDataUid()]);

        // The record of the event goes to the listeners after this one and, through
        // MetaDataAspect, to the File that addFile() returns.
        $event->setRecord(array_merge($event->getRecord(), $fields));
    }
}
