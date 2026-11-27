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
}
