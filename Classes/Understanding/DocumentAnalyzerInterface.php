<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Understanding;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;

interface DocumentAnalyzerInterface
{
    /**
     * Build exactly one ContentBrief from a SourceDocument.
     *
     * @param JobSnapshot $job the job (carries be_user for the budget guard)
     *
     * @throws AnalysisException when the LLM result cannot be normalized into a ContentBrief
     */
    public function analyze(SourceDocument $document, JobSnapshot $job): ContentBrief;
}
