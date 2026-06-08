<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Fetches a webpage via PSR-18 and extracts its main textual content with a deterministic
 * DOM strip (boilerplate node types removed, densest content container kept). JS-heavy
 * pages would be pre-rendered with the Plan-4 Chromium path; Plan 2 defaults to static HTML.
 */
final class WebPageFetcher
{
    /** Node names removed wholesale before text extraction. */
    private const BOILERPLATE_TAGS = ['script', 'style', 'nav', 'header', 'footer', 'aside', 'form', 'noscript', 'svg'];

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {}

    public function fetch(string $url): SourceDocument
    {
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('User-Agent', 'nr_repurpose/0.1 (+https://www.netresearch.de)')
            ->withHeader('Accept', 'text/html,application/xhtml+xml');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new IngestionException('URL not reachable: ' . $url, 1749379410, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new IngestionException(sprintf('URL returned HTTP %d: %s', $status, $url), 1749379411);
        }

        $html = (string) $response->getBody();
        if (trim($html) === '') {
            throw new IngestionException('URL returned an empty body: ' . $url, 1749379412);
        }

        $title = $this->extractTitle($html);
        $text = $this->extractMainText($html);

        if ($text === '') {
            throw new IngestionException('No readable content extracted from: ' . $url, 1749379413);
        }

        return new SourceDocument(
            title: $title,
            text: $text,
            sourceLabel: $url,
            pageCount: 0,
            languageHint: $this->detectLanguageHint($html),
            meta: ['fetchedVia' => 'static'],
        );
    }

    private function loadDom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Force UTF-8 interpretation regardless of a missing/late <meta charset>.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    private function extractTitle(string $html): string
    {
        $dom = $this->loadDom($html);
        $titles = $dom->getElementsByTagName('title');
        if ($titles->length > 0) {
            return trim((string) $titles->item(0)?->textContent);
        }
        $h1 = $dom->getElementsByTagName('h1');

        return $h1->length > 0 ? trim((string) $h1->item(0)?->textContent) : '';
    }

    private function detectLanguageHint(string $html): string
    {
        if (preg_match('/<html[^>]*\blang=["\']([a-zA-Z-]{2,})["\']/', $html, $m) === 1) {
            return strtolower(substr($m[1], 0, 2));
        }

        return '';
    }

    private function extractMainText(string $html): string
    {
        $dom = $this->loadDom($html);

        // 1) Drop boilerplate node types.
        foreach (self::BOILERPLATE_TAGS as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            // Snapshot to a static array — removing during a live NodeList iteration skips siblings.
            $toRemove = [];
            foreach ($nodes as $node) {
                $toRemove[] = $node;
            }
            foreach ($toRemove as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // 2) Drop HTML comments.
        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//comment()') ?: []) as $comment) {
            $comment->parentNode?->removeChild($comment);
        }

        // 3) Prefer the densest of <article>/<main>, else <body>.
        $candidate = $this->densestNode($dom, ['article', 'main']) ?? $dom->getElementsByTagName('body')->item(0);
        $raw = $candidate !== null ? (string) $candidate->textContent : (string) $dom->textContent;

        return $this->collapseWhitespace($raw);
    }

    /** @param list<string> $tagNames */
    private function densestNode(\DOMDocument $dom, array $tagNames): ?\DOMNode
    {
        $best = null;
        $bestLength = 0;
        foreach ($tagNames as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $length = strlen(trim((string) $node->textContent));
                if ($length > $bestLength) {
                    $bestLength = $length;
                    $best = $node;
                }
            }
        }

        return $best;
    }

    private function collapseWhitespace(string $text): string
    {
        // Normalise newlines, collapse runs of blank lines and intra-line whitespace.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\h*\R\h*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
