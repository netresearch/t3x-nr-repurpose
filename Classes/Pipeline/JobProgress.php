<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Pipeline;

use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;

/**
 * Per-generator progress reporter: maps a generator-local fraction (0..1) into the
 * job-global progress band [$from, $to] the orchestrator assigned to that generator
 * and persists it together with a human-readable detail (shown verbatim in the BE
 * module's result view, e.g. "Podcast: voicing segment 3/12").
 *
 * Reporting is best-effort UI feedback: it always re-passes JobStatus::Generating,
 * never changes the job's lifecycle state.
 */
final readonly class JobProgress
{
    public function __construct(
        private JobProcessingRepository $jobs,
        private int $jobUid,
        private float $from,
        private float $to,
    ) {}

    /** @param float $fraction generator-local progress (clamped to 0..1) */
    public function step(string $detail, float $fraction): void
    {
        $fraction = max(0.0, min(1.0, $fraction));
        $progress = (int) round($this->from + $fraction * ($this->to - $this->from));

        $this->jobs->markStatus($this->jobUid, JobStatus::Generating, $detail, $progress);
    }
}
