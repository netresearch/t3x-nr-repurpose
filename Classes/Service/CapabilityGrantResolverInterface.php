<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrRepurpose\Domain\ValueObject\CapabilityGrants;

interface CapabilityGrantResolverInterface
{
    /**
     * The capabilities the backend user's groups grant. A user that does not
     * exist, is disabled or deleted holds none.
     */
    public function resolve(int $beUserUid): CapabilityGrants;
}
