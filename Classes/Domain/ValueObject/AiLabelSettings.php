<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

/**
 * The AI-label settings of one run, resolved once by the orchestrator (ADR-005):
 *
 * - $generator:  "nr_repurpose <version>", written into every machine-readable marker.
 * - $imageLabel: the visible corner label for the rendered images, already in the
 *   language the artifact is written in; null when the extension setting
 *   `aiLabelImages` is off.
 * - $textLine:   the closing line appended to the copy-ready text formats, in the text's
 *   language; null when the extension setting `aiLabelTexts` is off (the default).
 *
 * The defaults (no visible labels) are what a context gets that the orchestrator did not
 * build, e.g. in unit tests; the machine-readable markers do not depend on them.
 */
final readonly class AiLabelSettings
{
    public const GENERATOR = 'nr_repurpose';

    public function __construct(
        public string $generator = self::GENERATOR,
        public ?string $imageLabel = null,
        public ?string $textLine = null,
    ) {}
}
