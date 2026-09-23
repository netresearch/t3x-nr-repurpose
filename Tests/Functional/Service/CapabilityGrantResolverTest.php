<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Netresearch\NrRepurpose\Domain\ValueObject\CapabilityGrants;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolver;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CapabilityGrantResolverTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/CapabilityGrantUsers.csv');
    }

    /** @return iterable<string, array{int, bool, bool}> */
    public static function users(): iterable
    {
        yield 'administrator holds both' => [10, true, true];
        yield 'group grants audio only' => [11, true, false];
        yield 'subgroup grants vision' => [12, false, true];
        yield 'group grants neither' => [13, false, false];
        yield 'two groups grant both' => [14, true, true];
        yield 'disabled user holds none' => [15, false, false];
        yield 'deleted user holds none' => [16, false, false];
        yield 'unknown user holds none' => [99, false, false];
        yield 'no user holds none' => [0, false, false];
    }

    #[DataProvider('users')]
    public function testResolvesTheGrantsOfTheJobOwnersGroups(int $beUserUid, bool $audio, bool $vision): void
    {
        $grants = $this->get(CapabilityGrantResolver::class)->resolve($beUserUid);

        self::assertEquals(new CapabilityGrants($audio, $vision), $grants);
    }

    public function testLeavesTheGlobalBackendUserUntouched(): void
    {
        $before = $GLOBALS['BE_USER'] ?? null;

        $this->get(CapabilityGrantResolver::class)->resolve(11);

        self::assertSame($before, $GLOBALS['BE_USER'] ?? null);
    }
}
