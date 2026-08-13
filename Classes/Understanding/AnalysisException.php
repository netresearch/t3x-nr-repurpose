<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Understanding;

use RuntimeException;

/**
 * Raised when the analysis result returned by nr-llm cannot be turned into a valid
 * ContentBrief (missing required keys, empty title/summary, …). Caught by the orchestrator,
 * which marks the job failed before any generator runs.
 */
final class AnalysisException extends RuntimeException {}
