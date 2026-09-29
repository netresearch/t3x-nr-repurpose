<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Exception;

use RuntimeException;

/**
 * Raised by JobSnapshot::fromRow() when a job row misses its uid or carries a
 * value of the wrong type or outside its column's set. The message names the
 * column and never the value, which can be the editor's source URL.
 */
final class MalformedJobRowException extends RuntimeException {}
