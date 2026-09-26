<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Review;

use Netresearch\NrRepurpose\Review\ReviewPermission;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Who may approve artifacts, resolved from real backend users and groups.
 */
#[CoversClass(ReviewPermission::class)]
final class ReviewPermissionTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/ReviewUsers.csv');
    }

    /** @return iterable<string, array{int, bool}> */
    public static function users(): iterable
    {
        yield 'administrator' => [10, true];
        yield 'group grants approve_artifacts' => [11, true];
        yield 'group grants another option only' => [12, false];
        yield 'subgroup grants approve_artifacts' => [13, true];
    }

    #[DataProvider('users')]
    public function testThePermissionFollowsTheGroups(int $uid, bool $allowed): void
    {
        self::assertSame($allowed, (new ReviewPermission())->allows($this->setUpBackendUser($uid)));
    }

    public function testNoBackendUserMayNotReview(): void
    {
        self::assertFalse((new ReviewPermission())->allows(null));
    }
}
