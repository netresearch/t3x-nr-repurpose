<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised by JobProcessingRepository when a write of a generation run finds that another
 * run holds the job's claim now (#238). The run stops; it writes nothing further.
 */
final class RunOwnershipLostException extends RuntimeException
{
    /**
     * The lost claim in $e or below it. Four sites ask: AbstractGenerator::failArtifactFrom(),
     * StoryGenerator's background call, and GenerationOrchestrator::failJob() and its crash catch.
     * No producer wraps a lost claim today: the speech and image adapters and the review service
     * rethrow it unchanged, and it passes nr-llm's pipeline unwrapped. The walk keeps a future
     * wrapper from turning a takeover into a failure.
     */
    public static function in(Throwable $e): ?self
    {
        for ($cause = $e; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof self) {
                return $cause;
            }
        }

        return null;
    }
}
