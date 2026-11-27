<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

/**
 * What one call of GenerationOrchestratorInterface::process() did with the job (#238): the
 * outcome of this run, not the job's status, which another run may have written since.
 */
enum RunOutcome: string
{
    /** This run finished the job with every format produced. */
    case Done = 'done';

    /** This run finished the job with some formats failed. */
    case PartiallyDone = 'partially_done';

    /** This run marked the job failed. */
    case Failed = 'failed';

    /** Another run took the job over; this run stopped without a final status. */
    case Lost = 'lost';

    /** The job was done or partially done before this run could claim it; nothing was done. */
    case AlreadyFinished = 'already_finished';

    /** The job had failed before this run could claim it; nothing was done. */
    case AlreadyFailed = 'already_failed';

    /** There is no such job. */
    case NotFound = 'not_found';

    /** Whether this run produced a result: Done or PartiallyDone. */
    public function produced(): bool
    {
        return $this === self::Done || $this === self::PartiallyDone;
    }
}
