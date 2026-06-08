<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Understanding;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;

interface DocumentAnalyzerInterface
{
    /**
     * Build exactly one ContentBrief from a SourceDocument.
     *
     * @param array<string,mixed> $jobRow raw job DB row (carries be_user for the budget guard)
     * @throws AnalysisException when the LLM result cannot be normalized into a ContentBrief
     */
    public function analyze(SourceDocument $document, array $jobRow): ContentBrief;
}
