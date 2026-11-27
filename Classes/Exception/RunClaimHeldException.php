<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Exception;

use RuntimeException;

/**
 * Raised by GenerationOrchestrator when another run holds a live claim on a job that is not
 * finished yet (#238). This run wrote nothing; the Messenger handler delivers the message
 * again after the lease, so a run whose worker died is taken over.
 */
final class RunClaimHeldException extends RuntimeException {}
