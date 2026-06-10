<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Pipeline;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\ResolvedPromptSnippets;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;

/**
 * Bundled, immutable per-run pipeline state. Built by GenerationOrchestrator after ingestion
 * and analysis; consumed unchanged by every ArtifactGeneratorInterface implementation.
 *
 * The progress reporter is the only per-generator part: the orchestrator derives one
 * context per generator via withProgress() so each generator reports into its own
 * progress band. A null reporter (e.g. in unit tests) makes reporting a no-op.
 */
final readonly class GenerationContext
{
    /** @param array<string,mixed> $jobRow raw job DB row (JobProcessingRepository::findRow) */
    public function __construct(
        public array $jobRow,
        public SourceDocument $document,
        public ContentBrief $brief,
        public string $theme,   // 'nr' | 'neutral'
        public int $beUser,     // for BudgetService::check()
        public ResolvedPromptSnippets $snippets = new ResolvedPromptSnippets(),
        public ?JobProgress $progress = null,
    ) {}

    public function jobUid(): int
    {
        return (int) $this->jobRow['uid'];
    }

    /** Derive the per-generator context carrying that generator's progress reporter. */
    public function withProgress(JobProgress $progress): self
    {
        return new self(
            $this->jobRow,
            $this->document,
            $this->brief,
            $this->theme,
            $this->beUser,
            $this->snippets,
            $progress,
        );
    }
}
