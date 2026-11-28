<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Fixtures;

use RuntimeException;

/** A failure a test makes happen: a provider, a database or a read that breaks (#238). */
final class SimulatedFailure extends RuntimeException {}
