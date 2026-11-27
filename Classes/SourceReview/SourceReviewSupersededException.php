<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\SourceReview;

use RuntimeException;

/**
 * The stored assessment changed since this run read it. Another run that took the job over is
 * reported as RunOwnershipLostException instead (#238); this one means a change outside any
 * run, and the orchestrator fails the job through the claim it still holds.
 */
final class SourceReviewSupersededException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'A newer source review superseded this run.',
            1794000101,
        );
    }
}
