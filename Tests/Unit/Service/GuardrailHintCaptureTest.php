<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Service;

use Netresearch\NrRepurpose\Service\GuardrailHintCapture;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\FakeGuardrailHintCollector;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\ShapelessGuardrailHintCollector;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class GuardrailHintCaptureTest extends TestCase
{
    protected function tearDown(): void
    {
        FakeGuardrailHintCollector::reset();
        parent::tearDown();
    }

    /** The released nr-llm has no collector: the call runs as before and reports nothing. */
    public function testWithoutTheCollectorTheCallRunsAndReportsNoHints(): void
    {
        $calls   = 0;
        $capture = new GuardrailHintCapture();

        $captured = $capture->capture(static function () use (&$calls): string {
            ++$calls;
            FakeGuardrailHintCollector::record(FakeGuardrailHintCollector::hint('violation', 'never seen'));

            return 'text';
        });

        self::assertSame(['result' => 'text', 'hints' => []], $captured);
        self::assertSame(1, $calls);
    }

    /** A class of that name without capture() is no collector: the call runs as without one. */
    public function testAClassWithoutCaptureIsNoCollector(): void
    {
        $calls   = 0;
        $capture = new GuardrailHintCapture(new stdClass());

        $captured = $capture->capture(static function () use (&$calls): string {
            ++$calls;

            return 'text';
        });

        self::assertSame(['result' => 'text', 'hints' => []], $captured);
        self::assertSame(1, $calls);
    }

    /** A collector answer of another shape costs the hints, never the call's result. */
    public function testAnAnswerOfAnotherShapeKeepsTheResultAndReportsNoHints(): void
    {
        $calls   = 0;
        $capture = new GuardrailHintCapture(new ShapelessGuardrailHintCollector());

        $captured = $capture->capture(static function () use (&$calls): array {
            ++$calls;

            return ['summary' => 'text'];
        });

        self::assertSame(['result' => ['summary' => 'text'], 'hints' => []], $captured);
        self::assertSame(1, $calls);
    }

    public function testTheCollectorsHintsAreReadByShape(): void
    {
        $capture = new GuardrailHintCapture(new FakeGuardrailHintCollector());

        $captured = $capture->capture(static function (): string {
            FakeGuardrailHintCollector::record(
                FakeGuardrailHintCollector::hint('violation', 'Breaks "No discounts": "50 % Rabatt"', '50 % Rabatt'),
                ['kind' => 'unavailable', 'message' => 'The rules were not checked.'],
                ['kind' => 'violation', 'message' => ''],
                'not a hint',
                ['message' => 'No kind given.'],
            );

            return 'text';
        });

        self::assertSame('text', $captured['result']);
        self::assertSame([
            ['kind' => 'violation', 'message' => 'Breaks "No discounts": "50 % Rabatt"'],
            ['kind' => 'unavailable', 'message' => 'The rules were not checked.'],
            ['kind' => '', 'message' => 'No kind given.'],
        ], $captured['hints']);
    }

    public function testAThrowingCallThrowsOn(): void
    {
        $capture = new GuardrailHintCapture(new FakeGuardrailHintCollector());

        $this->expectExceptionObject($failure = new RuntimeException('stopped'));

        $capture->capture(static function () use ($failure): never {
            throw $failure;
        });
    }
}
