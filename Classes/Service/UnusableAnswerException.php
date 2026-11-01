<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use RuntimeException;

/**
 * The text model answered twice with something that is no JSON object, usually because a
 * reasoning model spent its token limit on thinking. The message is a fixed text.
 */
final class UnusableAnswerException extends RuntimeException {}
