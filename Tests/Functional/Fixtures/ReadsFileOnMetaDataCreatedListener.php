<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Fixtures;

use RuntimeException;
use TYPO3\CMS\Core\Resource\Event\AfterFileMetaDataCreatedEvent;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Stands in for an extension that generates alt text on upload: on the metadata
 * record FAL creates while it indexes a new file, it reads the file and builds the
 * image_url a vision call would send, in the exact shape of the nr-llm-compat bridge
 * ('data:image/jpeg;base64,' . base64_encode($contents)).
 *
 * Registered by the fixture extension `nrrepurpose_failing_metadata_fixture`. It only
 * records while a test switches it on, and throws while $fail is set.
 */
final class ReadsFileOnMetaDataCreatedListener
{
    public static bool $record = false;

    public static bool $fail = false;

    /** @var list<string> image_url values built from the file as the listener saw it */
    public static array $imageUrls = [];

    public function __construct(private readonly ResourceFactory $resourceFactory) {}

    public function __invoke(AfterFileMetaDataCreatedEvent $event): void
    {
        if (!self::$record && !self::$fail) {
            return;
        }

        $contents          = $this->resourceFactory->getFileObject($event->getFileUid())->getContents();
        self::$imageUrls[] = 'data:image/jpeg;base64,' . base64_encode($contents);

        if (self::$fail) {
            throw new RuntimeException('Alt-text generation failed on purpose', 1790000902);
        }
    }

    public static function reset(): void
    {
        self::$record    = false;
        self::$fail      = false;
        self::$imageUrls = [];
    }
}
