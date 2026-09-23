<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

/**
 * Which of the two spend-heavy capabilities the job owner's backend groups
 * grant (customPermOptions `nrrepurpose:generate_audio` and
 * `nrrepurpose:generate_vision`). Resolved once per run for the job's
 * be_user; an administrator holds both.
 */
final readonly class CapabilityGrants
{
    public function __construct(
        public bool $audio,
        public bool $vision,
    ) {}

    public static function all(): self
    {
        return new self(true, true);
    }

    public static function none(): self
    {
        return new self(false, false);
    }
}
