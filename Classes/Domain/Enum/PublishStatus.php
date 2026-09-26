<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

/**
 * Where a social post is on its way out. Publishing marks the row while the command
 * sends it, so two runs at the same time cannot send it twice.
 */
enum PublishStatus: string
{
    case None       = '';
    case Scheduled  = 'scheduled';
    case Publishing = 'publishing';
    case Published  = 'published';
    case Failed     = 'failed';

    /** LLL key (locallang.xlf) of the label. */
    public function getLabelKey(): string
    {
        return 'publish.' . ($this === self::None ? 'none' : $this->value);
    }
}
