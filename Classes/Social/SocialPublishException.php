<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Social;

use RuntimeException;

/** A publishing channel did not accept a post; the message is stored on the row. */
final class SocialPublishException extends RuntimeException {}
