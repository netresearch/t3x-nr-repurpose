<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Exception;

use RuntimeException;

/**
 * Raised when a step hands on an artifact or image with no bytes: an empty file is
 * never stored and an empty image never reaches an AI call.
 * Extends RuntimeException so existing catch contracts keep working.
 */
final class EmptyArtifactContentException extends RuntimeException {}
