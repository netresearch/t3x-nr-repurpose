<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Social;

/** What one run of the publishing command did. */
final readonly class PublishReport
{
    public function __construct(
        /** Approved posts whose time had come. */
        public int $due,
        public int $published,
        public int $failed,
        /** False: no channel configured, nothing was sent or changed. */
        public bool $channelConfigured,
    ) {}
}
