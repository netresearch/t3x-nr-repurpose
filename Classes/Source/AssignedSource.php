<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Source;

/** What assigning the form's source to a job leaves for the controller to do. */
final readonly class AssignedSource
{
    public function __construct(
        // The sys_file to attach to the job once it has a uid; 0 for any other source.
        public int $fileUid = 0,
        // The tree holds more pages than one job reads; the form says so before the job runs.
        public bool $cutByPageLimit = false,
    ) {}
}
