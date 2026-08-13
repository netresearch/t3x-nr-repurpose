<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Exception;

use RuntimeException;

/**
 * Raised when no default FAL storage is configured/available to store generated artifacts.
 * Extends RuntimeException so existing catch contracts keep working.
 */
final class DefaultStorageUnavailableException extends RuntimeException {}
