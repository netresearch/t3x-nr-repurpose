<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

/**
 * A TextLimiter result: the text and how it was cut. The mode is stored in the artifact
 * metadata so the result view can say whether a post lost whole sentences or was cut
 * mid-sentence.
 */
final readonly class TextCut
{
    /** The text fitted; nothing was cut. */
    public const NONE = '';

    /** Cut after the last whole sentence that fits. */
    public const SENTENCE = 'sentence';

    /** Cut at a word boundary, "…" appended. */
    public const WORD = 'word';

    public function __construct(
        public string $text,
        public string $mode,
    ) {}

    public function wasCut(): bool
    {
        return $this->mode !== self::NONE;
    }
}
