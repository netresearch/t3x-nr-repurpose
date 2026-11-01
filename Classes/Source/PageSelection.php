<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Source;

/** The result of a page tree walk: the pages to read, nearest first, and what was left out. */
final readonly class PageSelection
{
    /** @param list<array<string, mixed>> $pages pages rows */
    public function __construct(
        public array $pages,
        public int $skippedHidden = 0,
        public int $skippedRestricted = 0,
        public int $skippedExcluded = 0,
        public int $skippedNoAccess = 0,
        public bool $cutByPageLimit = false,
    ) {}
}
