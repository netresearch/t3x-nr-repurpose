<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering\Process;

interface ProcessRunnerInterface
{
    /**
     * Run a command (argv form, no shell) with optional stdin. Never throws on a non-zero
     * exit — the caller inspects ProcessResult and raises a RenderingException with context.
     *
     * @param list<string> $command argv: [binary, arg, ...]
     * @param string|null  $stdin   fed to the process stdin (e.g. HTML for the renderer)
     */
    public function run(array $command, ?string $stdin = null, float $timeoutSeconds = 60.0): ProcessResult;
}
