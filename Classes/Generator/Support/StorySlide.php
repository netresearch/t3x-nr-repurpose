<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

use Netresearch\NrRepurpose\Domain\Enum\StorySlideRole;

/**
 * One slide of the Instagram-story carousel: a cover (hook/title), a key-point slide,
 * or the outro (takeaway + source attribution). Parsed from the single LLM carousel
 * response by StoryGenerator.
 */
final readonly class StorySlide
{
    public function __construct(
        public StorySlideRole $role,
        public string $headline,
        public string $subline,
    ) {}
}
