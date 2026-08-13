<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrRepurpose\Command\GenerateCommand;

return [
    'nr_repurpose:generate' => [
        'class'       => GenerateCommand::class,
        'schedulable' => false,
    ],
];
