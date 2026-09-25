<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

use RuntimeException;

/**
 * The LLM answered, but not in the shape a text generator can use. The message is written
 * for the editor: it lands verbatim (behind the format label) in the artifact's error.
 */
final class InvalidLlmOutputException extends RuntimeException {}
