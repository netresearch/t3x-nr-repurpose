<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Exception;

use RuntimeException;

/**
 * Raised by JobProcessingRepository when a write of a generation run finds that another
 * run holds the job's claim now (#238). The run stops; it writes nothing further.
 */
final class RunOwnershipLostException extends RuntimeException {}
