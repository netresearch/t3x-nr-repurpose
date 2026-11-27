<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Netresearch\NrRepurpose\Exception\RunOwnershipLostException;
use Netresearch\NrRepurpose\Pipeline\RunHeartbeatInterface;

/**
 * Counts the renewals of the held claims (#238) and, when told to, reports the job as lost
 * to another run.
 */
final class RecordingHeartbeat implements RunHeartbeatInterface
{
    public int $heldRenewals = 0;

    public function __construct(private readonly bool $lost = false) {}

    public static function lost(): self
    {
        return new self(true);
    }

    public function renew(int $jobUid): void
    {
        $this->renewHeld();
    }

    public function renewHeld(): void
    {
        ++$this->heldRenewals;
        if ($this->lost) {
            throw new RunOwnershipLostException('Another run holds job 7 now', 1795000001);
        }
    }
}
