<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Fixtures;

use TYPO3\CMS\Core\Resource\Event\AfterFileMetaDataCreatedEvent;

/**
 * Stands in for the upload listener of EXT:ai_filemetadata 1.6.x
 * (EnrichFileMetadataAfterCreation): it skips a metadata record whose `alternative` is
 * set and otherwise sends the file to a vision model, recorded here as a call.
 *
 * Registered by the fixture extension `nrrepurpose_ai_filemetadata_fixture` under the
 * real listener's service id, which ApplyPendingFileMetadata names in its `before`.
 * Idle until a test switches it on.
 */
final class AltTextGeneratorStandInListener
{
    public static bool $active = false;

    /** @var list<int> uids of the files a vision call would have been made for */
    public static array $modelCalls = [];

    public function __invoke(AfterFileMetaDataCreatedEvent $event): void
    {
        if (!self::$active) {
            return;
        }

        if ((string) ($event->getRecord()['alternative'] ?? '') !== '') {
            return;
        }

        self::$modelCalls[] = $event->getFileUid();
    }

    public static function reset(): void
    {
        self::$active     = false;
        self::$modelCalls = [];
    }
}
