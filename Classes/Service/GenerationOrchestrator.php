<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Psr\Log\LoggerInterface;

/**
 * Drives one job through the pipeline. In the walking skeleton the only generator is the
 * stub; per-artifact isolation and status transitions are real so Plan 5 just swaps generators.
 */
final class GenerationOrchestrator implements GenerationOrchestratorInterface
{
    /** @var list<ArtifactGeneratorInterface> */
    private array $generators;

    /** @param iterable<ArtifactGeneratorInterface> $generators */
    public function __construct(
        private readonly JobProcessingRepository $jobs,
        private readonly LoggerInterface $logger,
        iterable $generators,
    ) {
        $this->generators = $generators instanceof \Traversable
            ? iterator_to_array($generators, false)
            : array_values($generators);
    }

    public function process(int $jobUid): void
    {
        $row = $this->jobs->findRow($jobUid);
        if ($row === null) {
            $this->logger->warning('Job not found, skipping', ['job' => $jobUid]);

            return;
        }
        if (JobStatus::from((string)$row['status'])->isTerminal()) {
            return; // idempotent: never reprocess a finished job
        }

        $this->jobs->markStatus($jobUid, JobStatus::Generating, 'generating', 10);

        $applicable = array_values(array_filter($this->generators, static fn(ArtifactGeneratorInterface $g): bool => $g->supports($row)));
        $total = max(1, count($applicable));
        $ok = 0;
        foreach ($applicable as $i => $generator) {
            $ok += $generator->generate($row) ? 1 : 0;
            $this->jobs->markStatus($jobUid, JobStatus::Generating, 'generating', (int)(10 + 80 * ($i + 1) / $total));
        }

        $final = match (true) {
            $ok === count($applicable) => JobStatus::Done,
            $ok > 0 => JobStatus::PartiallyDone,
            default => JobStatus::Failed,
        };
        $this->jobs->markStatus($jobUid, $final, 'done', 100);
    }
}
