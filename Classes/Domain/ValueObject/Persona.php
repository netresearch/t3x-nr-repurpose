<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

/**
 * One resolved podcast persona: display name (used as the speaker label in the dialogue),
 * the persona description (the snippet text, woven into the dialogue prompt) and the
 * optional TTS voice from the snippet metadata (null = use the configured fallback voices).
 */
final readonly class Persona
{
    public function __construct(
        public string $name,
        public string $description,
        public ?string $voice = null,
    ) {}
}
