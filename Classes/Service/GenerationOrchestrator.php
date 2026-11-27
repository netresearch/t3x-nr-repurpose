<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrLlm\Exception\GuardrailPolicyException;
use Netresearch\NrRepurpose\Domain\Enum\FailureReason;
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceReport;
use Netresearch\NrRepurpose\Exception\MalformedJobRowException;
use Netresearch\NrRepurpose\Exception\RunClaimHeldException;
use Netresearch\NrRepurpose\Exception\RunOwnershipLostException;
use Netresearch\NrRepurpose\Generator\AbstractGenerator;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Pipeline\JobProgress;
use Netresearch\NrRepurpose\Pipeline\PromptSnippetResolver;
use Netresearch\NrRepurpose\Provenance\AiLabelSettingsFactory;
use Netresearch\NrRepurpose\SourceReview\SourceCapture;
use Netresearch\NrRepurpose\SourceReview\SourceReviewGate;
use Netresearch\NrRepurpose\SourceReview\SourceReviewRejectedException;
use Netresearch\NrRepurpose\Understanding\AnalysisException;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use Netresearch\NrRepurpose\Utility\ScalarCast;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Traversable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Drives one job through the real pipeline: findRow -> ingest -> analyze -> resolve prompt
 * snippets -> build context -> run each applicable generator(ctx) -> final status. Per-artifact
 * isolation and the status transitions are preserved from Plan 1; ingestion/analysis/resolution
 * failures abort before any artifact.
 */
final readonly class GenerationOrchestrator implements GenerationOrchestratorInterface
{
    /** @var list<ArtifactGeneratorInterface> */
    private array $generators;

    /** @param iterable<ArtifactGeneratorInterface> $generators */
    public function __construct(
        private JobProcessingRepository $jobs,
        private LoggerInterface $logger,
        private SourceIngestionServiceInterface $ingestion,
        private DocumentAnalyzerInterface $analyzer,
        private PromptSnippetResolver $snippetResolver,
        private TechnicalActorContextInterface $technicalActor,
        private ExtensionConfiguration $extensionConfiguration,
        private CapabilityGrantResolverInterface $grantResolver,
        private AiLabelSettingsFactory $aiLabelSettings,
        iterable $generators,
        private ?SourceReviewGate $sourceReview = null,
    ) {
        $this->generators = $generators instanceof Traversable
            ? iterator_to_array($generators, false)
            : array_values($generators);
    }

    /**
     * Both callers - the Messenger handler and the CLI command - run without an
     * authenticated backend user: TYPO3 boots an unauthenticated
     * CommandLineUserAuthentication for a console request. nr_vault has no actor
     * to authorise in that state and denies every secret read, so the very first
     * provider call fails with "Access denied to secret ... insufficient
     * permissions" and the job dies at 20% in the analysis step, before any
     * artifact exists. Owner and group grants on the secret cannot help: that
     * branch of the access check never consults them.
     *
     * The wrapper therefore goes here rather than into either caller - one choke
     * point, both paths covered, and a future third caller too.
     *
     * With technicalBeUserUid at 0 nothing changes, so an installation that has
     * not configured an actor behaves exactly as before instead of failing in a
     * new way.
     */
    public function process(int $jobUid): RunOutcome
    {
        $actorUid = $this->technicalActorUid();

        // <= 0, not === 0: runAs() takes a positive uid, and a negative one
        // reached it because only zero was filtered. There is no actor below 1.
        if ($actorUid <= 0) {
            return $this->processJob($jobUid);
        }

        $outcome = $this->technicalActor->runAs($actorUid, fn (): RunOutcome => $this->processJob($jobUid));

        return $outcome instanceof RunOutcome ? $outcome : RunOutcome::Failed;
    }

    private function technicalActorUid(): int
    {
        try {
            return ScalarCast::int($this->extensionConfiguration->get('nr_repurpose', 'technicalBeUserUid'));
        } catch (Throwable) {
            // Not configured at all - the extension shipped without an
            // ext_conf_template until this was added.
            return 0;
        }
    }

    private function processJob(int $jobUid): RunOutcome
    {
        $row = $this->jobs->findRow($jobUid);
        if ($row === null) {
            $this->logger->warning(
                'Job not found, skipping',
                ['job' => $jobUid],
            );

            return RunOutcome::NotFound;
        }

        // Idempotent: never reprocess a finished job. Checked on the raw status before
        // the row is parsed, so a finished job whose row no longer parses stays as it is.
        if ($this->isFinished($row)) {
            return $this->finishedOutcome($row);
        }

        // One run per job (#238): a second delivery while a live run holds the job writes
        // nothing. The claim also refuses a job that finished since the read above.
        if (!$this->jobs->claimRun($jobUid)) {
            $row = $this->jobs->findRow($jobUid);
            if ($row === null) {
                return RunOutcome::NotFound;
            }

            if ($this->isFinished($row)) {
                return $this->finishedOutcome($row);
            }

            throw new RunClaimHeldException(sprintf('Another run holds job %d', $jobUid), 1795000002);
        }

        try {
            return $this->runClaimed($jobUid);
        } catch (RunOwnershipLostException $e) {
            // Another run took the job over; it owns the status and the artifacts now.
            $this->logger->warning('Run lost the job to another run, stopping', ['job' => $jobUid, 'exception' => $e]);

            return RunOutcome::Lost;
        } catch (Throwable $e) {
            // A crash past the step handling (a generator that threw, a database error) fails
            // the job here, while the claim binds the write; the Messenger handler's own catch
            // is left with failures before the claim. Fixed text, as there (#77).
            $this->logger->error('Generation job crashed', ['job' => $jobUid, 'exception' => $e]);
            try {
                $this->jobs->markFailed($jobUid, 'Generation failed', FailureClassifier::reasonFor($e));
            } catch (RunOwnershipLostException) {
                // Another run took the job over in between; its outcome stands.
                return RunOutcome::Lost;
            }

            return RunOutcome::Failed;
        } finally {
            $this->jobs->forgetRun($jobUid);
        }
    }

    /**
     * A job finished before this run: failed stays a failure for the caller (#238).
     *
     * @param array<string, mixed> $row
     */
    private function finishedOutcome(array $row): RunOutcome
    {
        return ($row['status'] ?? null) === JobStatus::Failed->value ? RunOutcome::AlreadyFailed : RunOutcome::AlreadyFinished;
    }

    /** @param array<string, mixed> $row */
    private function isFinished(array $row): bool
    {
        $status = $row['status'] ?? null;

        return is_string($status) && JobStatus::tryFrom($status)?->isTerminal() === true;
    }

    /** The pipeline of a run that holds the job's claim; every write below is bound to it. */
    private function runClaimed(int $jobUid): RunOutcome
    {
        // Read again: the row the claim was won on, not the one read before it.
        $row = $this->jobs->findRow($jobUid);
        if ($row === null) {
            return RunOutcome::NotFound;
        }

        // The only conversion of the raw row; everything downstream reads the snapshot.
        try {
            $job = JobSnapshot::fromRow($row);
        } catch (MalformedJobRowException $e) {
            $this->failJob($jobUid, 'Reading the job', $e);

            return RunOutcome::Failed;
        }

        // 1) Ingestion — turn the source into a SourceDocument.
        $this->jobs->markStatus($jobUid, JobStatus::Ingesting, 'ingesting', 5);
        try {
            $capture = $this->sourceReview?->captureSource($row);
            // Ingestion reads the file the fingerprint names, not the reference read again.
            $document = $this->ingestion->ingest(
                $capture instanceof SourceCapture && $capture->fileUid > 0 ? $job->withSourceFileUid($capture->fileUid) : $job,
            );
        } catch (Throwable $e) {
            $this->failJob($jobUid, 'Ingestion', $e);

            return RunOutcome::Failed;
        }

        // A page source says what it used and what it left out; the result view shows that.
        $report = $document->meta['sourceReport'] ?? null;
        if ($report instanceof SourceReport) {
            $this->jobs->setSourceReport($jobUid, $report);
        }

        // 2) Understanding — build exactly one ContentBrief.
        $this->jobs->markStatus($jobUid, JobStatus::Analyzing, 'analyzing', 20);
        try {
            $brief = $this->analyzer->analyze($document, $job);
        } catch (Throwable $e) {
            $this->failJob($jobUid, 'Analysis', $e);

            return RunOutcome::Failed;
        }

        // Record the detected language on the job for the BE result view.
        $this->jobs->setLanguageDetected($jobUid, $brief->language);
        try {
            $briefReview = $this->sourceReview?->brief(
                $job,
                $document,
                $brief,
                $capture?->hash,
            ) ?? [];
        } catch (Throwable $e) {
            // A review that another run replaced arrives as RunOwnershipLostException, which
            // failJob() passes on. A SourceReviewSupersededException means the stored review
            // changed under a claim this run still holds, outside any run: the run cannot
            // trust its baseline and fails the job through its claim (#238).
            $this->failJob($jobUid, 'Source fidelity check', $e);

            return RunOutcome::Failed;
        }

        // 3) Resolve the editor's prompt-snippet selection ONCE for this run (analysis stays
        // snippet-free by design — snippets steer generation only). An empty selection
        // short-circuits inside the resolver without any repository access.
        try {
            $snippets = $this->snippetResolver->resolve($job->promptSnippets);
        } catch (Throwable $e) {
            $this->failJob($jobUid, 'Prompt snippet resolution', $e);

            return RunOutcome::Failed;
        }

        // 4) Build the shared per-run context.
        $ctx = new GenerationContext(
            job: $job,
            document: $document,
            brief: $brief,
            theme: $job->theme,
            beUser: $job->beUser,
            snippets: $snippets,
            grants: $this->grantResolver->resolve($job->beUser),
            // Visible labels in the language the artifacts are written in (ADR-005).
            aiLabel: $this->aiLabelSettings->create($brief->language),
            sourceReview: $this->sourceReview,
            briefReview: $briefReview,
        );

        // 5) Generation — per-artifact isolation; a single failure does not abort siblings.
        // Clear any artifacts from a prior (interrupted or re-queued) run so reprocessing yields
        // a clean set instead of duplicating rows. Terminal jobs never reach here (guarded above).
        $this->jobs->deleteArtifactsForJob($jobUid);
        $this->jobs->markStatus(
            $jobUid,
            JobStatus::Generating,
            'generating',
            30,
        );

        $applicable = array_values(
            array_filter(
                $this->generators,
                static fn (
                    ArtifactGeneratorInterface $g,
                ): bool => $g->supports($ctx),
            ),
        );
        $count = count($applicable);
        $ok    = 0;
        foreach ($applicable as $i => $generator) {
            // Each generator reports fine-grained steps into its own slice of the
            // 30..100 generation band; the context is derived per generator.
            $band = new JobProgress(
                $this->jobs,
                $jobUid,
                30 + 70 * $i / $count,
                30 + 70 * ($i + 1) / $count,
            );
            try {
                $success = $generator->generate($ctx->withProgress($band));
            } finally {
                // Temp directories a generator left behind (the story slides, the
                // podcast segments) go once it is done, also when it threw.
                if ($generator instanceof AbstractGenerator) {
                    $generator->removeTempDirs();
                }
            }

            $ok += $success ? 1 : 0;
            // round(), not truncation: JobProgress::step() rounds into the band, so the
            // band-end write must agree or progress could step back by one percent.
            $progress = $count > 0 ? (int) round(30 + 70 * ($i + 1) / $count) : 100;
            // currentStep null on purpose: keep the generator's last human-readable
            // detail visible instead of overwriting it with the generic slug.
            $this->jobs->markStatus(
                $jobUid,
                JobStatus::Generating,
                null,
                $progress,
            );
        }

        // 6) Final status. Each artifact carries its own error; the job names how many
        // formats failed, so a failed job is never without a reason (#77).
        $failed                   = $count - $ok;
        [$final, $error, $reason] = match (true) {
            $failed === 0 => [JobStatus::Done, '', null],
            $ok > 0       => [
                JobStatus::PartiallyDone,
                sprintf(
                    '%d of %d formats failed; each failed artifact shows its error',
                    $failed,
                    $count,
                ),
                FailureReason::SomeFormatsFailed,
            ],
            default => [
                JobStatus::Failed,
                sprintf(
                    'All %d formats failed; each artifact shows its error',
                    $count,
                ),
                FailureReason::AllFormatsFailed,
            ],
        };
        $this->jobs->finishJob($jobUid, $final, $error, $reason);

        return match ($final) {
            JobStatus::Done          => RunOutcome::Done,
            JobStatus::PartiallyDone => RunOutcome::PartiallyDone,
            default                  => RunOutcome::Failed,
        };
    }

    /**
     * The job's error_message is shown to every module user, so only this extension's own
     * ingestion and analysis messages reach it: fixed texts, or naming the editor's own source
     * URL without user name, password, query and fragment (SourceUrlRedactor). Any other exception (the text model, Guzzle, poppler, the database) can carry
     * provider detail, paths or SQL: the row gets "<step> failed". The exception goes to the
     * log either way. What the editor reads comes from the stored reason (FailureReason, ADR-011).
     * A guardrail's refusal is stored without any quoted passage of the generated text (nr-llm ADR-288).
     */
    private function failJob(int $jobUid, string $step, Throwable $e): void
    {
        // A run that lost the job writes no failure either.
        if ($e instanceof RunOwnershipLostException) {
            throw $e;
        }

        $this->logger->error(
            $step . ' failed',
            ['job' => $jobUid, 'exception' => $e],
        );
        $this->jobs->markFailed(
            $jobUid,
            match (true) {
                $e instanceof GuardrailPolicyException                                                                             => GuardrailRefusal::storableMessage($e),
                $e instanceof IngestionException || $e instanceof AnalysisException || $e instanceof SourceReviewRejectedException => $e->getMessage(),
                default                                                                                                            => $step . ' failed',
            },
            FailureClassifier::reasonFor($e),
        );
    }
}
