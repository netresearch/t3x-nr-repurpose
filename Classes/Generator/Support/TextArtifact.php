<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

/**
 * One validated text result, ready to be stored as one artifact row: the plain-text
 * rendering goes to `script_text` (what an editor copies), the structured content to
 * `metadata.content` (what the result view renders).
 */
final readonly class TextArtifact
{
    /** @param array<string, mixed> $content */
    public function __construct(
        public string $variant,
        public string $plainText,
        public array $content,
    ) {}
}
