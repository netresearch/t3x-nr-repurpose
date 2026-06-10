<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Understanding;

use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Psr\Log\LoggerInterface;

/**
 * Produces exactly one ContentBrief from a SourceDocument via nr-llm CompletionService (JSON mode).
 *
 * - Source language is detected by the LLM (returned in the JSON `language` field); the document
 *   languageHint is used as a fallback only.
 * - Large documents use Map-Reduce: chunk -> per-chunk summary (map) -> single synthesis (reduce),
 *   to respect provider token limits. The chunk threshold/size are configurable.
 * - Every completion call carries a beUserUid on ChatOptions, opting it into nr-llm's
 *   BudgetMiddleware; an over-budget run throws BudgetExceededException.
 */
final class DocumentAnalyzer implements DocumentAnalyzerInterface
{
    private const SYSTEM_PROMPT =
        'You are a precise editorial analyst. You read a source document and produce a faithful '
        . 'structured brief. Numbers, names and labels must stay exactly as in the source. '
        . 'Detect the source language and report it as an ISO-639-1 code. '
        . 'Output ONLY valid JSON, no prose around it.';

    private const MAP_SYSTEM_PROMPT =
        'You summarize one section of a larger document faithfully and concisely. '
        . 'Preserve numbers, names and labels exactly. Output ONLY valid JSON.';

    public function __construct(
        private readonly CompletionServiceInterface $completion,
        private readonly LoggerInterface $logger,
        private readonly int $chunkThreshold = 24000,
        private readonly int $chunkSize = 12000,
    ) {}

    public function analyze(SourceDocument $document, array $jobRow): ContentBrief
    {
        $beUser = (int) ($jobRow['be_user'] ?? 0);
        $text = trim($document->text);

        if ($text === '') {
            throw new AnalysisException('Cannot analyze an empty source document', 1749384000);
        }

        if (mb_strlen($text) > $this->chunkThreshold) {
            $synthesisInput = $this->mapReduce($text, $beUser);
        } else {
            $synthesisInput = $text;
        }

        $prompt = $this->buildSynthesisPrompt($document, $synthesisInput);
        $decoded = $this->completion->completeJson($prompt, $this->jsonOptions(self::SYSTEM_PROMPT, $beUser));

        if (!$this->hasRequiredBriefKeys($decoded)) {
            // The model occasionally answers with a differently-shaped object
            // (e.g. localized key names or a bare sections list). One corrective
            // retry that names the offending shape recovers most of these; only
            // a second miss fails the job, now with diagnostic detail.
            $this->logger->warning('Analysis synthesis returned an unusable shape, retrying once', [
                'receivedKeys' => array_keys($decoded),
            ]);
            $retryPrompt = $prompt . "\n\nIMPORTANT: Your previous answer used the keys ["
                . implode(', ', array_map(strval(...), array_keys($decoded)))
                . '] and was rejected. Respond again with EXACTLY the JSON keys '
                . '"title", "summary", "keyPoints", "sections", "audience", "language" '
                . '— non-empty "title" and "summary" are mandatory.';
            $decoded = $this->completion->completeJson($retryPrompt, $this->jsonOptions(self::SYSTEM_PROMPT, $beUser));
        }

        return $this->toContentBrief($decoded, $document);
    }

    /** @param array<string,mixed> $decoded */
    private function hasRequiredBriefKeys(array $decoded): bool
    {
        return is_string($decoded['title'] ?? null) && trim($decoded['title']) !== ''
            && is_string($decoded['summary'] ?? null) && trim($decoded['summary']) !== '';
    }

    /**
     * Map step: summarize each chunk; returns the concatenated per-chunk summaries used as the
     * reduce input.
     */
    private function mapReduce(string $text, int $beUser): string
    {
        $chunks = $this->splitIntoChunks($text);
        $this->logger->info('DocumentAnalyzer map-reduce', ['chunks' => count($chunks)]);

        $summaries = [];
        foreach ($chunks as $index => $chunk) {
            $prompt = 'Summarize this section faithfully as JSON with keys '
                . '"summary" (string) and "keyPoints" (array of strings).' . "\n\n"
                . 'SECTION ' . ($index + 1) . ":\n" . $chunk;
            $decoded = $this->completion->completeJson(
                $prompt,
                $this->jsonOptions(self::MAP_SYSTEM_PROMPT, $beUser),
            );

            $summary = is_string($decoded['summary'] ?? null) ? $decoded['summary'] : '';
            $points = $this->normalizeStringList($decoded['keyPoints'] ?? []);
            $summaries[] = trim($summary . "\n" . implode("\n", array_map(
                static fn (string $p): string => '- ' . $p,
                $points,
            )));
        }

        return "Section summaries of the source document:\n\n" . implode("\n\n", $summaries);
    }

    /** @return list<string> */
    private function splitIntoChunks(string $text): array
    {
        $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $candidate = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
            if (mb_strlen($candidate) > $this->chunkSize && $current !== '') {
                $chunks[] = $current;
                $current = $paragraph;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [$text] : $chunks;
    }

    private function buildSynthesisPrompt(SourceDocument $document, string $body): string
    {
        return 'Analyze the following document and produce a faithful brief as JSON with keys: '
            . '"title" (string), "summary" (string), "keyPoints" (array of strings), '
            . '"sections" (array of {"heading": string, "body": string}), '
            . '"audience" (string), "language" (ISO-639-1 string of the source language).' . "\n\n"
            . 'Source title: ' . ($document->title !== '' ? $document->title : '(none)') . "\n"
            . 'Source label: ' . $document->sourceLabel . "\n\n"
            . "DOCUMENT:\n" . $body;
    }

    private function jsonOptions(string $systemPrompt, int $beUser): ChatOptions
    {
        $options = new ChatOptions(
            temperature: 0.3,
            responseFormat: 'json',
            systemPrompt: $systemPrompt,
        );

        // beUserUid opts the call into nr-llm BudgetMiddleware; 0 = skip (anonymous/CLI).
        return $beUser > 0 ? $options->withBeUserUid($beUser) : $options;
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function toContentBrief(array $decoded, SourceDocument $document): ContentBrief
    {
        $title = is_string($decoded['title'] ?? null) ? trim($decoded['title']) : '';
        $summary = is_string($decoded['summary'] ?? null) ? trim($decoded['summary']) : '';

        if ($title === '' || $summary === '') {
            throw new AnalysisException(
                sprintf(
                    'Analysis result is missing the required "title" and/or "summary" key (received keys: %s)',
                    implode(', ', array_map(strval(...), array_keys($decoded))),
                ),
                1749384100,
            );
        }

        $language = is_string($decoded['language'] ?? null) && $decoded['language'] !== ''
            ? strtolower(substr($decoded['language'], 0, 5))
            : ($document->languageHint !== '' ? $document->languageHint : 'en');

        $audience = is_string($decoded['audience'] ?? null) ? trim($decoded['audience']) : '';

        return new ContentBrief(
            title: $title,
            summary: $summary,
            keyPoints: $this->normalizeStringList($decoded['keyPoints'] ?? []),
            sections: $this->normalizeSections($decoded['sections'] ?? []),
            audience: $audience,
            language: $language,
        );
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * @return list<array{heading:string, body:string}>
     */
    private function normalizeSections(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $section) {
            if (!is_array($section)) {
                continue;
            }
            $heading = is_string($section['heading'] ?? null) ? trim($section['heading']) : '';
            $body = is_string($section['body'] ?? null) ? trim($section['body']) : '';
            if ($heading === '' && $body === '') {
                continue;
            }
            $out[] = ['heading' => $heading, 'body' => $body];
        }

        return $out;
    }
}
