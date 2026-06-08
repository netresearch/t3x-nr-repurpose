<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering\Process;

/** Result of running an external command via ProcessRunnerInterface. */
final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
