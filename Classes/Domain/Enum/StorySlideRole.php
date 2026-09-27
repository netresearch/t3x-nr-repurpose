<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

/**
 * Role of one story slide. The value is what the carousel LLM call answers with, what the
 * slide template compares against and what the artifact metadata stores as `role`.
 */
enum StorySlideRole: string
{
    /** Hook/title slide. */
    case Cover = 'cover';

    /** One key point. */
    case Point = 'point';

    /** Takeaway and source attribution. */
    case Outro = 'outro';
}
