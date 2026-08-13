<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;

/**
 * Tier 3 — layout/table-aware extraction via `pdftotext -layout`. Used by the auto
 * dispatcher for pages that look tabular and by forced `tables` mode.
 */
class PdfLayoutExtractor
{
    public function __construct(private readonly PopplerRunnerInterface $poppler) {}

    /** Extract one 1-based page preserving columns/tables. */
    public function extractPage(string $absPdfPath, int $page): string
    {
        return $this->poppler->extractLayout($absPdfPath, $page);
    }
}
