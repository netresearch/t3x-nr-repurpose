<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering\Process;

use Netresearch\NrRepurpose\Rendering\Process\SymfonyProcessRunner;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

#[CoversClass(SymfonyProcessRunner::class)]
final class SymfonyProcessRunnerTest extends TestCase
{
    public function testThePassedEnvironmentReachesTheChildAndTheInheritedOneStays(): void
    {
        $result = (new SymfonyProcessRunner(new NullLogger()))->run(
            ['sh', '-c', 'printf "%s|%s" "$NR_REPURPOSE_TEST_VAR" "${PATH:+path-set}"'],
            null,
            10.0,
            ['NR_REPURPOSE_TEST_VAR' => '/opt/chromium'],
        );

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame('/opt/chromium|path-set', $result->stdout);
    }

    /**
     * Symfony's ProcessTimedOutException message holds the command line (script and output
     * paths); every caller's error reaches an artifact's or job's error message, which every
     * module user sees. It must stay in the server log.
     */
    public function testATimeoutThrowsAFixedMessageAndLogsTheCause(): void
    {
        $logger = new RecordingLogger();
        $e      = $this->failure($logger, ['sh', '-c', 'sleep 5 # /tmp/nrrepurpose-secret-dir/out.png'], 0.1);

        self::assertSame('External process timed out', $e->getMessage());
        self::assertSame(1749400501, $e->getCode());
        self::assertStringNotContainsString('nrrepurpose-secret-dir', $e->getMessage());
        $inner = $e->getPrevious();
        self::assertInstanceOf(ProcessTimedOutException::class, $inner);
        self::assertStringContainsString('nrrepurpose-secret-dir', $inner->getMessage(), 'the cause keeps the detail');
        $this->assertLogged($logger, $inner);
    }

    public function testASignalledProcessThrowsAFixedMessageAndLogsTheCause(): void
    {
        $logger = new RecordingLogger();
        $e      = $this->failure($logger, ['sh', '-c', 'kill -KILL $$'], 10.0);

        self::assertSame('External process was terminated by a signal', $e->getMessage());
        self::assertSame(1749400502, $e->getCode());
        $inner = $e->getPrevious();
        self::assertInstanceOf(ProcessSignaledException::class, $inner);
        $this->assertLogged($logger, $inner);
    }

    /**
     * @param list<string> $command
     */
    private function failure(RecordingLogger $logger, array $command, float $timeout): RenderingException
    {
        try {
            (new SymfonyProcessRunner($logger))->run($command, null, $timeout);
        } catch (Throwable $e) {
            self::assertInstanceOf(RenderingException::class, $e, 'a Symfony Process exception must not escape the runner: ' . $e::class);

            return $e;
        }

        self::fail('Expected a RenderingException');
    }

    private function assertLogged(RecordingLogger $logger, Throwable $inner): void
    {
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame($inner, $logger->records[0]['context']['exception'] ?? null);
    }
}
