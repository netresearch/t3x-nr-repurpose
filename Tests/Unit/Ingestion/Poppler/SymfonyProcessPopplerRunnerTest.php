<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion\Poppler;

use Netresearch\NrRepurpose\Exception\PopplerProcessFailedException;
use Netresearch\NrRepurpose\Exception\RunOwnershipLostException;
use Netresearch\NrRepurpose\Ingestion\Poppler\SymfonyProcessPopplerRunner;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\ProcessTimeoutAssertions;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingHeartbeat;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\SlowExecutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A Poppler call that runs into the timeout. Symfony's ProcessTimedOutException is not a
 * ProcessFailedException, and its message names the command line with the stored PDF's
 * absolute path. The ingestion error reaches the job's error message, shown to every module
 * user. Runs without poppler-utils: the binary is a SlowExecutable.
 */
#[CoversClass(SymfonyProcessPopplerRunner::class)]
final class SymfonyProcessPopplerRunnerTest extends TestCase
{
    use ProcessTimeoutAssertions;

    public function testARasterizeTimeoutThrowsAFixedMessageAndLogsTheCause(): void
    {
        $slow   = new SlowExecutable();
        $logger = new RecordingLogger();
        $runner = new SymfonyProcessPopplerRunner($logger, $slow->path, 'pdftotext', 0.1);

        try {
            self::assertTimeoutIsFixedAndLogged(
                static fn (): string => $runner->rasterizePage('/tmp/source.pdf', 1),
                $logger,
                PopplerProcessFailedException::class,
                'pdftoppm failed for page 1',
                1749379431,
            );
        } finally {
            $slow->remove();
        }
    }

    public function testAnExtractLayoutTimeoutThrowsAFixedMessageAndLogsTheCause(): void
    {
        $slow   = new SlowExecutable();
        $logger = new RecordingLogger();
        $runner = new SymfonyProcessPopplerRunner($logger, 'pdftoppm', $slow->path, 0.1);

        try {
            self::assertTimeoutIsFixedAndLogged(
                static fn (): string => $runner->extractLayout('/tmp/source.pdf', 1),
                $logger,
                PopplerProcessFailedException::class,
                'pdftotext -layout failed for page 1',
                1749379432,
            );
        } finally {
            $slow->remove();
        }
    }

    /** Each poppler process renews the run's claim first; a lost claim starts none (#238). */
    public function testEachPopplerProcessRenewsTheRunsClaimFirst(): void
    {
        $heartbeat = new RecordingHeartbeat();
        $runner    = new SymfonyProcessPopplerRunner(new RecordingLogger(), '/nonexistent/pdftoppm', '/nonexistent/pdftotext', 5.0, $heartbeat);
        foreach ([static fn (): string => $runner->rasterizePage('/nonexistent.pdf', 1), static fn (): string => $runner->extractLayout('/nonexistent.pdf', 1)] as $call) {
            try {
                $call();
            } catch (PopplerProcessFailedException) {
                // The binary is missing; only the renewal before it counts here.
            }
        }

        self::assertSame(2, $heartbeat->heldRenewals);

        $lost = new SymfonyProcessPopplerRunner(new RecordingLogger(), '/nonexistent/pdftoppm', '/nonexistent/pdftotext', 5.0, RecordingHeartbeat::lost());
        foreach ([static fn (): string => $lost->rasterizePage('/nonexistent.pdf', 1), static fn (): string => $lost->extractLayout('/nonexistent.pdf', 1)] as $call) {
            try {
                $call();
                self::fail('A lost claim must stop the run');
            } catch (RunOwnershipLostException) {
                // Thrown before the process, so no PopplerProcessFailedException.
            }
        }
    }
}
