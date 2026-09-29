<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

/**
 * How TextLimiter shortened a text. The value is stored in the social post metadata as
 * `cutMode`, which the result view reads; rows stored before the field existed have none.
 */
enum CutMode: string
{
    /** The text fitted; nothing was cut. */
    case None = '';

    /** Cut after the last whole sentence that fits. */
    case Sentence = 'sentence';

    /** Cut at a word boundary, "…" appended. */
    case Word = 'word';
}
