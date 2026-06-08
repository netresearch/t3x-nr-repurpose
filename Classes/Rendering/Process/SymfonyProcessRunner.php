<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering\Process;

use Symfony\Component\Process\Process;

/**
 * Default ProcessRunnerInterface: builds a Symfony Process from an argv array (no shell),
 * feeds optional stdin via setInput(), runs it and returns the captured result. Does NOT
 * use mustRun(): a non-zero exit is reported through ProcessResult so callers can attach
 * tool-specific context to a RenderingException.
 */
final class SymfonyProcessRunner implements ProcessRunnerInterface
{
    public function run(array $command, ?string $stdin = null, float $timeoutSeconds = 60.0): ProcessResult
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        if ($stdin !== null) {
            $process->setInput($stdin);
        }
        $exitCode = $process->run();

        return new ProcessResult(
            (int) $exitCode,
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }
}
