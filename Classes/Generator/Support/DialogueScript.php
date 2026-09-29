<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

/**
 * The podcast dialogue as PodcastGenerator built it: the usable turns plus the exact
 * system and user prompt of the script call, which the artifact metadata records
 * verbatim (prompts.system / prompts.user).
 */
final readonly class DialogueScript
{
    /**
     * @param list<DialogueTurn> $turns
     */
    public function __construct(
        public array $turns,
        public string $systemPrompt,
        public string $userPrompt,
    ) {}
}
