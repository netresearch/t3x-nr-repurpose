<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\SourceReview;

use RuntimeException;

/** Another run owns the stored assessment; this run must stop without changing its state. */
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
