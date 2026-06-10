<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;

/**
 * Records every markStatus() call so tests can assert the fine-grained progress
 * reporting (current_step detail + mapped progress percent) without a database.
 * Shared by the JobProgress unit test and the generator progress tests.
 */
final class StatusRecordingJobRepository extends JobProcessingRepository
{
    /** @var list<array{jobUid: int, status: JobStatus, step: string|null, progress: int|null}> */
    public array $calls = [];

    public function __construct() {}

    public function markStatus(int $jobUid, JobStatus $status, ?string $currentStep = null, ?int $progress = null): void
    {
        $this->calls[] = ['jobUid' => $jobUid, 'status' => $status, 'step' => $currentStep, 'progress' => $progress];
    }

    /** @return list<string|null> */
    public function steps(): array
    {
        return array_column($this->calls, 'step');
    }

    /** @return list<int|null> */
    public function progresses(): array
    {
        return array_column($this->calls, 'progress');
    }
}
