<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\SourceReview;

use RuntimeException;

final class SourceReviewRejectedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Source fidelity check did not pass. Inspect the separate automatic review before retrying.',
            1794000100,
        );
    }
}
