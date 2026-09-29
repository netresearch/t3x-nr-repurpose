<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;

/**
 * Builds a JobSnapshot from the columns a test cares about, through the same
 * JobSnapshot::fromRow() the orchestrator uses; a row without uid gets uid 1.
 */
final class JobSnapshots
{
    /** @param array<string,mixed> $columns */
    public static function of(array $columns = []): JobSnapshot
    {
        return JobSnapshot::fromRow($columns + ['uid' => 1]);
    }
}
