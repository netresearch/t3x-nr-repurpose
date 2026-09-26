<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

/**
 * An editor's decision about a finished artifact. Only an approved social post can be
 * scheduled for publishing.
 */
enum ReviewStatus: string
{
    case Open     = '';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Bootstrap badge variant, as ArtifactStatus::getSeverity(). */
    public function getSeverity(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Open     => 'secondary',
        };
    }

    /** LLL key (locallang.xlf) of the label. */
    public function getLabelKey(): string
    {
        return 'review.' . ($this === self::Open ? 'open' : $this->value);
    }
}
