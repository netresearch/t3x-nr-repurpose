<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Utility;

use Stringable;

/**
 * The (int) and (string) casts for values whose type the caller does not declare:
 * database rows, extension settings, console arguments, decoded JSON.
 *
 * A scalar or null gives exactly what the cast operator gives. A value the operator
 * would turn into a warning, an Error or an arbitrary 0/1 (array, object, resource)
 * gives 0 or ''; a Stringable object still gives its string.
 */
final class ScalarCast
{
    public static function int(mixed $value): int
    {
        return is_scalar($value) || $value === null ? (int) $value : 0;
    }

    public static function string(mixed $value): string
    {
        if (is_scalar($value) || $value === null || $value instanceof Stringable) {
            return (string) $value;
        }

        return '';
    }
}
