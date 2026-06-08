<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

/**
 * The single understanding result for one run. Produced by DocumentAnalyzer and shared
 * unchanged by all three generators (one analysis per run, not three).
 */
final readonly class ContentBrief
{
    /**
     * @param list<string> $keyPoints
     * @param list<array{heading:string, body:string}> $sections
     */
    public function __construct(
        public string $title,
        public string $summary,
        public array $keyPoints,
        public array $sections,
        public string $audience,
        public string $language,   // detected source language (ISO-639-1), drives the output language
    ) {}
}
