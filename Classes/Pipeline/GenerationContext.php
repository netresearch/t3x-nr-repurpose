<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Pipeline;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\ResolvedPromptSnippets;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;

/**
 * Bundled, immutable per-run pipeline state. Built by GenerationOrchestrator after ingestion
 * and analysis; consumed unchanged by every ArtifactGeneratorInterface implementation.
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
    ) {}

    public function jobUid(): int
    {
        return (int) $this->jobRow['uid'];
    }
}
