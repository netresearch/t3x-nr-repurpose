<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering\Process;

use Netresearch\NrRepurpose\Rendering\RenderingException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Default ProcessRunnerInterface: builds a Symfony Process from an argv array (no shell),
 * feeds optional stdin via setInput(), runs it and returns the captured result. Does NOT
 * use mustRun(): a non-zero exit is reported through ProcessResult so callers can attach
 * tool-specific context to a RenderingException.
 *
 * A timeout, a start failure or a signal makes Symfony Process throw, and its message names
 * the command line (script, input and output paths) or the working directory. Every caller's
 * error reaches an artifact's error_message, which every module user sees, so the Symfony
 * exception goes to the server log and a RenderingException with a fixed message is thrown.
 */
final readonly class SymfonyProcessRunner implements ProcessRunnerInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public function run(array $command, ?string $stdin = null, float $timeoutSeconds = 60.0, array $env = []): ProcessResult
    {
        try {
            // Symfony adds the inherited environment to $env; entries in $env win.
            $process = new Process($command, null, $env);
            $process->setTimeout($timeoutSeconds);
            if ($stdin !== null) {
                $process->setInput($stdin);
            }

            $exitCode = $process->run();
        } catch (ExceptionInterface $e) {
            [$message, $code] = match (true) {
                $e instanceof ProcessTimedOutException => ['External process timed out', 1749400501],
                $e instanceof ProcessSignaledException => ['External process was terminated by a signal', 1749400502],
                default                                => ['External process could not be run', 1749400503],
            };
            $this->logger->error($message, ['exception' => $e]);

            throw RenderingException::because($message, $code, $e);
        }

        return new ProcessResult(
            $exitCode,
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }
}
