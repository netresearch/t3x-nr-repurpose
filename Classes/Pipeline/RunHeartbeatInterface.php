<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Pipeline;

use Netresearch\NrRepurpose\Exception\RunOwnershipLostException;

/**
 * Renews a generation run's claim on its job in a long stretch without a write to the job
 * (#238): a live run must not look like a dead worker's to the next delivery.
 */
interface RunHeartbeatInterface
{
    /** @throws RunOwnershipLostException when another run holds the job now */
    public function renew(int $jobUid): void;

    /**
     * Renew every claim this process holds; nothing when it holds none (a web request, a run
     * of another extension). RunClaimRenewalMiddleware calls it before each provider call.
     *
     * @throws RunOwnershipLostException when another run holds one of the jobs now
     */
    public function renewHeld(): void;
}
