<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use Psr\Log\LoggerInterface;

/**
 * Drives one job through the real pipeline: findRow -> ingest -> analyze -> build context ->
 * run each applicable generator(ctx) -> final status. Per-artifact isolation and the status
 * transitions are preserved from Plan 1; ingestion/analysis failures abort before any artifact.
 */
final class GenerationOrchestrator implements GenerationOrchestratorInterface
{
    /** @var list<ArtifactGeneratorInterface> */
    private array $generators;

    /** @param iterable<ArtifactGeneratorInterface> $generators */
    public function __construct(
        private readonly JobProcessingRepository $jobs,
        private readonly LoggerInterface $logger,
        private readonly SourceIngestionServiceInterface $ingestion,
        private readonly DocumentAnalyzerInterface $analyzer,
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
        if (JobStatus::from((string) $row['status'])->isTerminal()) {
            return; // idempotent: never reprocess a finished job
        }

        // 1) Ingestion — turn the source into a SourceDocument.
        $this->jobs->markStatus($jobUid, JobStatus::Ingesting, 'ingesting', 5);
        try {
            $document = $this->ingestion->ingest($row);
        } catch (\Throwable $e) {
            $this->logger->error('Ingestion failed', ['job' => $jobUid, 'exception' => $e->getMessage()]);
            $this->jobs->markFailed($jobUid, $e->getMessage());

            return;
        }

        // 2) Understanding — build exactly one ContentBrief.
        $this->jobs->markStatus($jobUid, JobStatus::Analyzing, 'analyzing', 20);
        try {
            $brief = $this->analyzer->analyze($document, $row);
        } catch (\Throwable $e) {
            $this->logger->error('Analysis failed', ['job' => $jobUid, 'exception' => $e->getMessage()]);
            $this->jobs->markFailed($jobUid, $e->getMessage());

            return;
        }

        // Record the detected language on the job for the BE result view.
        $this->jobs->setLanguageDetected($jobUid, $brief->language);

        // 3) Build the shared per-run context.
        $ctx = new GenerationContext(
            jobRow: $row,
            document: $document,
            brief: $brief,
            theme: (string) ($row['theme'] ?? 'nr'),
            beUser: (int) ($row['be_user'] ?? 0),
        );

        // 4) Generation — per-artifact isolation; a single failure does not abort siblings.
        $this->jobs->markStatus($jobUid, JobStatus::Generating, 'generating', 30);
        $applicable = array_values(array_filter(
            $this->generators,
            static fn (ArtifactGeneratorInterface $g): bool => $g->supports($ctx),
        ));
        $count = count($applicable);
        $ok = 0;
        foreach ($applicable as $i => $generator) {
            $success = $generator->generate($ctx);
            $ok += $success ? 1 : 0;
            $progress = $count > 0 ? (int) (30 + 70 * ($i + 1) / $count) : 100;
            $this->jobs->markStatus($jobUid, JobStatus::Generating, 'generating', $progress);
        }

        // 5) Final status (Plan 1 logic preserved).
        $final = $ok === $count
            ? JobStatus::Done
            : ($ok > 0 ? JobStatus::PartiallyDone : JobStatus::Failed);
        $this->jobs->markStatus($jobUid, $final, 'done', 100);
    }
}
