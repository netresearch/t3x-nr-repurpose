<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

/**
 * Runs the generation pipeline for one job and returns what this run did (#238). Implemented by
 * GenerationOrchestrator; the Messenger handler and nr_repurpose:generate call it.
 *
 * @internal
 */
interface GenerationOrchestratorInterface
{
    public function process(int $jobUid): RunOutcome;
}
