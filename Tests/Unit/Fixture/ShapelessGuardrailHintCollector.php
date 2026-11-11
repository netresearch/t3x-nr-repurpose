<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

/**
 * A collector whose capture() runs the call and answers in a shape nr-llm's does
 * not have: GuardrailHintCapture must keep the call's result and report no hints.
 */
final class ShapelessGuardrailHintCollector
{
    public function capture(callable $call): string
    {
        $call();

        return 'not the documented shape';
    }
}
