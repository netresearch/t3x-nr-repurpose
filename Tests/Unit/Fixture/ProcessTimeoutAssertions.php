<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Closure;
use Psr\Log\LogLevel;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * For tests that run a SlowExecutable through the real Symfony Process with a short
 * timeout: Symfony's ProcessTimedOutException names the command line, and with it the
 * executable's path (SlowExecutable::MARKER). The error that leaves the call reaches a
 * stored error message, which every module user sees, so it has to carry a fixed text
 * while the Symfony exception goes to the server log and stays attached as the cause.
 */
trait ProcessTimeoutAssertions
{
    /**
     * @param Closure(): mixed        $call
     * @param class-string<Throwable> $expectedClass
     */
    private static function assertTimeoutIsFixedAndLogged(
        Closure $call,
        RecordingLogger $logger,
        string $expectedClass,
        string $expectedMessage,
        int $expectedCode,
    ): void {
        $cause = null;
        try {
            $call();
            self::fail('Expected ' . $expectedClass);
        } catch (Throwable $e) {
            self::assertInstanceOf($expectedClass, $e, 'unexpected ' . $e::class . ': ' . $e->getMessage());
            self::assertSame($expectedMessage, $e->getMessage());
            self::assertSame($expectedCode, $e->getCode());
            self::assertStringNotContainsString(SlowExecutable::MARKER, $e->getMessage());

            $cause = $e->getPrevious();
            self::assertInstanceOf(ProcessTimedOutException::class, $cause);
            self::assertStringContainsString(SlowExecutable::MARKER, $cause->getMessage(), 'the cause keeps the detail');
        }

        self::assertNotNull($cause);
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame($cause, $logger->records[0]['context']['exception'] ?? null);
    }
}
