<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Exception;

use RuntimeException;

/** Raised by JobProcessingRepository::updateArtifact() when the row it fills has been deleted (#238). */
final class ArtifactRowMissingException extends RuntimeException {}
