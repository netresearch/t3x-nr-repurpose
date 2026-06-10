<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\ValueObject\PromptSnippetSelection;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Pipeline\JobProgress;
use Netresearch\NrRepurpose\Pipeline\PromptSnippetResolver;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use Psr\Log\LoggerInterface;

/**
 * Drives one job through the real pipeline: findRow -> ingest -> analyze -> resolve prompt
 * snippets -> build context -> run each applicable generator(ctx) -> final status. Per-artifact
 * isolation and the status transitions are preserved from Plan 1; ingestion/analysis/resolution
 * failures abort before any artifact.
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
        private readonly PromptSnippetResolver $snippetResolver,
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

        // 3) Resolve the editor's prompt-snippet selection ONCE for this run (analysis stays
        // snippet-free by design — snippets steer generation only). An empty selection
        // short-circuits inside the resolver without any repository access.
        try {
            $snippets = $this->snippetResolver->resolve(
                PromptSnippetSelection::fromJson((string) ($row['prompt_snippets'] ?? '')),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Prompt snippet resolution failed', ['job' => $jobUid, 'exception' => $e->getMessage()]);
            $this->jobs->markFailed($jobUid, $e->getMessage());

            return;
        }

        // 4) Build the shared per-run context.
        $ctx = new GenerationContext(
            jobRow: $row,
            document: $document,
            brief: $brief,
            theme: (string) ($row['theme'] ?? 'nr'),
            beUser: (int) ($row['be_user'] ?? 0),
            snippets: $snippets,
        );

        // 5) Generation — per-artifact isolation; a single failure does not abort siblings.
        // Clear any artifacts from a prior (interrupted or re-queued) run so reprocessing yields
        // a clean set instead of duplicating rows. Terminal jobs never reach here (guarded above).
        $this->jobs->deleteArtifactsForJob($jobUid);
        $this->jobs->markStatus($jobUid, JobStatus::Generating, 'generating', 30);
        $applicable = array_values(array_filter(
            $this->generators,
            static fn (ArtifactGeneratorInterface $g): bool => $g->supports($ctx),
        ));
        $count = count($applicable);
        $ok = 0;
        foreach ($applicable as $i => $generator) {
            // Each generator reports fine-grained steps into its own slice of the
            // 30..100 generation band; the context is derived per generator.
            $band = new JobProgress(
                $this->jobs,
                $jobUid,
                30 + 70 * $i / $count,
                30 + 70 * ($i + 1) / $count,
            );
            $success = $generator->generate($ctx->withProgress($band));
            $ok += $success ? 1 : 0;
            $progress = $count > 0 ? (int) (30 + 70 * ($i + 1) / $count) : 100;
            // currentStep null on purpose: keep the generator's last human-readable
            // detail visible instead of overwriting it with the generic slug.
            $this->jobs->markStatus($jobUid, JobStatus::Generating, null, $progress);
        }

        // 6) Final status (Plan 1 logic preserved).
        $final = $ok === $count
            ? JobStatus::Done
            : ($ok > 0 ? JobStatus::PartiallyDone : JobStatus::Failed);
        $this->jobs->markStatus($jobUid, $final, 'done', 100);
    }
}
