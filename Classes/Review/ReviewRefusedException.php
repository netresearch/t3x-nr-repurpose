<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Review;

use RuntimeException;

/**
 * A review or scheduling step that is not allowed for the artifact as it is. The
 * message is the locallang.xlf key of the text the editor sees.
 */
final class ReviewRefusedException extends RuntimeException {}
