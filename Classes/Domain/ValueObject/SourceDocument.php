<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

/**
 * The normalized result of ingesting a job source (webpage or PDF): cleaned plain text
 * plus best-effort metadata. Consumed by the Understanding layer (DocumentAnalyzer).
 */
final readonly class SourceDocument
{
    /**
     * @param array<string, mixed> $meta e.g. ['tiersUsed' => ['text','vision'], 'fetchedVia' => 'chromium']
     */
    public function __construct(
        public string $title,
        public string $text,
        public string $sourceLabel,
        public int $pageCount,
        public string $languageHint,
        public array $meta = [],
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }
}
