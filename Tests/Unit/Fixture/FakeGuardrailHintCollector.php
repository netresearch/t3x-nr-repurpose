<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use stdClass;

/**
 * Stands in for nr-llm's GuardrailHintCollector (nr-llm ADR-288), which the released
 * nr-llm does not have yet: same capture() shape, and record() adds to every open
 * capture while the call runs, as nr-llm's middleware does. GuardrailHintCapture
 * resolves it with makeInstance(), which builds a new instance each time, so the open
 * captures are static; reset() in tearDown.
 */
final class FakeGuardrailHintCollector
{
    /** @var list<list<mixed>> */
    private static array $open = [];

    public static function record(mixed ...$hints): void
    {
        foreach (self::$open as $index => $collected) {
            self::$open[$index] = [...$collected, ...$hints];
        }
    }

    public static function reset(): void
    {
        self::$open = [];
    }

    /** @return array{result: mixed, hints: list<mixed>} */
    public function capture(callable $call): array
    {
        self::$open[] = [];
        try {
            $result = $call();
        } finally {
            $hints = array_pop(self::$open) ?? [];
        }

        return ['result' => $result, 'hints' => $hints];
    }

    /** A hint as nr-llm's GuardrailHint carries it: an object with public properties. */
    public static function hint(string $kind, string $message, string $passage = ''): stdClass
    {
        return (object) ['guardrail' => 'RuleSetJudge', 'kind' => $kind, 'message' => $message, 'rule' => 'No discounts', 'passage' => $passage];
    }
}
