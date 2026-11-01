<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Source;

use RuntimeException;

/** The form's source was refused; the message is the label key the controller shows. */
final class SourceRefusedException extends RuntimeException {}
