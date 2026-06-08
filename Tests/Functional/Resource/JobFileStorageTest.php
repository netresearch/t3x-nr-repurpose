<?php

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
