<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

/**
 * One spoken turn of the podcast dialogue. The voice is resolved by PodcastGenerator from
 * the speaker: Host A => nova, Host B => onyx by default, or the persona's voice.
 */
final readonly class DialogueTurn
{
    public function __construct(
        public string $speaker,
        public string $text,
        public string $voice,
    ) {}
}
