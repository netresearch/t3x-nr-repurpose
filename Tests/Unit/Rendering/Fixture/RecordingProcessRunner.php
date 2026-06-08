<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering\Fixture;

use Netresearch\NrRepurpose\Rendering\Process\ProcessResult;
use Netresearch\NrRepurpose\Rendering\Process\ProcessRunnerInterface;

/**
 * Records the argv/stdin of each run() call and returns canned results in order, so unit
 * tests can assert the exact command an infra primitive builds without spawning a process.
 */
final class RecordingProcessRunner implements ProcessRunnerInterface
{
    /** @var list<array{command: list<string>, stdin: ?string, timeout: float}> */
    public array $calls = [];

    /** @var list<ProcessResult> */
    private array $results;

    public function __construct(ProcessResult ...$results)
    {
        $this->results = $results === [] ? [new ProcessResult(0, '', '')] : array_values($results);
    }

    public function run(array $command, ?string $stdin = null, float $timeoutSeconds = 60.0): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'stdin' => $stdin, 'timeout' => $timeoutSeconds];

        $result = $this->results[count($this->calls) - 1] ?? $this->results[count($this->results) - 1];

        // Simulate the tool producing its output file so callers' is_file() checks pass.
        if ($result->successful()) {
            $outIndex = array_search('--out', $command, true);
            if ($outIndex !== false && isset($command[$outIndex + 1])) {
                @file_put_contents($command[$outIndex + 1], "\x89PNG\r\n\x1a\n");
            }
        }

        return $result;
    }
}
