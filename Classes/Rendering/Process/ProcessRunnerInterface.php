<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering\Process;

use Netresearch\NrRepurpose\Rendering\RenderingException;

interface ProcessRunnerInterface
{
    /**
     * Run a command (argv form, no shell) with optional stdin. Never throws on a non-zero
     * exit — the caller inspects ProcessResult and raises a RenderingException with context.
     * A run that cannot finish (timeout, start failure, signal) throws a RenderingException
     * with a fixed message; the message of the underlying error, which names the command
     * line, goes to the server log only.
     *
     * @param list<string>          $command argv: [binary, arg, ...]
     * @param string|null           $stdin   fed to the process stdin (e.g. HTML for the renderer)
     * @param array<string, string> $env     variables set for the child on top of the inherited
     *                                       environment; putenv() is no substitute, because
     *                                       Symfony Process forwards only getenv() keys that
     *                                       are also in $_SERVER
     *
     * @throws RenderingException when the process times out, cannot be started or is signalled
     */
    public function run(array $command, ?string $stdin = null, float $timeoutSeconds = 60.0, array $env = []): ProcessResult;
}
