<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

/**
 * The normalized result of ingesting a job source (webpage or PDF): cleaned plain text
 * plus best-effort metadata. Consumed by the Understanding layer (DocumentAnalyzer).
 */
final readonly class SourceDocument
{
    public string $title;

    public string $text;

    /**
     * @param array<string, mixed> $meta e.g. ['tiersUsed' => ['text','vision'], 'fetchedVia' => 'chromium']
     */
    public function __construct(
        string $title,
        string $text,
        public string $sourceLabel,
        public int $pageCount,
        public string $languageHint,
        public array $meta = [],
    ) {
        // Drop invalid UTF-8 byte sequences. Ingested HTML/PDF text can carry
        // mis-declared-charset or truncated-multibyte bytes; left unsanitised they
        // make the downstream LLM request fail in json_encode() with
        // "Malformed UTF-8 characters, possibly incorrectly encoded".
        $this->title = self::toValidUtf8($title);
        $this->text  = self::toValidUtf8($text);
    }

    private static function toValidUtf8(string $value): string
    {
        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }
}
