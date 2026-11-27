<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\SourceReview;

/**
 * What captureSource() read before ingestion: the fingerprint of the selected source and,
 * for a pdf_fal job, the sys_file uid of the reference that fingerprint names (0 otherwise).
 * Ingestion reads that file instead of resolving the reference a second time (#238).
 */
final readonly class SourceCapture
{
    public function __construct(
        public string $hash,
        public int $fileUid = 0,
    ) {}
}
