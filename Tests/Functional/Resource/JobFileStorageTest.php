<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Resource;

use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;

final class JobFileStorageTest extends AbstractFunctionalTestCase
{
    public function testStoreWritesContentAndReturnsResolvableFile(): void
    {
        $storage = $this->get(JobFileStorage::class);

        $file = $storage->store('hello world', 'unit-test.txt');

        self::assertGreaterThan(0, $file->getUid());
        self::assertSame('hello world', $file->getContents());
        self::assertStringContainsString('repurpose', $file->getIdentifier());
    }
}
