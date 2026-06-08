<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Netresearch\NrRepurpose\Domain\Enum\PdfMode;
use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;

/**
 * Single ingestion entry point. URL sources go through WebPageFetcher; PDF sources are
 * resolved to a local file and run through a per-page tier dispatcher honoring pdf_mode:
 *   - auto:   tier 1 (embedded text); sparse page -> tier 2 (Vision OCR); tabular page -> tier 3 (layout)
 *   - text:   tier 1 for every page
 *   - vision: tier 2 for every page
 *   - tables: tier 3 for every page
 */
final class SourceIngestionService implements SourceIngestionServiceInterface
{
    public function __construct(
        private readonly WebPageFetcher $webPageFetcher,
        private readonly PdfFileResolver $pdfFileResolver,
        private readonly PdfTextExtractor $textExtractor,
        private readonly PdfVisionExtractor $visionExtractor,
        private readonly PdfLayoutExtractor $layoutExtractor,
    ) {}

    public function ingest(array $jobRow): SourceDocument
    {
        $type = SourceType::tryFrom((string) ($jobRow['source_type'] ?? ''));
        if ($type === null) {
            throw new IngestionException('Unknown source_type: ' . (string) ($jobRow['source_type'] ?? ''), 1749379450);
        }

        return match ($type) {
            SourceType::Url => $this->ingestUrl((string) ($jobRow['source_value'] ?? '')),
            SourceType::PdfUrl, SourceType::PdfFal => $this->ingestPdf($jobRow),
        };
    }

    private function ingestUrl(string $url): SourceDocument
    {
        if (trim($url) === '') {
            throw new IngestionException('url job has an empty source_value', 1749379451);
        }

        return $this->webPageFetcher->fetch($url);
    }

    /** @param array<string,mixed> $jobRow */
    private function ingestPdf(array $jobRow): SourceDocument
    {
        $mode = PdfMode::fromJobValue((string) ($jobRow['pdf_mode'] ?? 'auto'));
        $beUser = (int) ($jobRow['be_user'] ?? 0);
        $absPath = $this->pdfFileResolver->resolve($jobRow);

        $pages = $this->textExtractor->extract($absPath);

        $texts = [];
        $tiers = [];
        foreach ($pages as $page) {
            [$text, $tier] = $this->extractPage($absPath, $page, $mode, $beUser);
            if (trim($text) !== '') {
                $texts[] = $text;
                $tiers[$tier] = true;
            }
        }

        $body = trim(implode("\n\n", $texts));
        if ($body === '') {
            throw new IngestionException('No text could be extracted from the PDF: ' . $absPath, 1749379452);
        }

        return new SourceDocument(
            title: '',
            text: $body,
            sourceLabel: basename($absPath),
            pageCount: count($pages),
            languageHint: '',
            meta: ['tiersUsed' => $this->orderTiers($tiers)],
        );
    }

    /**
     * @param array{page:int,text:string,isSparse:bool} $page
     * @return array{0:string,1:string} [pageText, tierLabel]
     */
    private function extractPage(string $absPath, array $page, PdfMode $mode, int $beUser): array
    {
        return match ($mode) {
            PdfMode::Text => [$page['text'], 'text'],
            PdfMode::Vision => [$this->visionExtractor->ocrPage($absPath, $page['page'], $beUser), 'vision'],
            PdfMode::Tables => [$this->layoutExtractor->extractPage($absPath, $page['page']), 'tables'],
            PdfMode::Auto => $this->autoPage($absPath, $page, $beUser),
        };
    }

    /**
     * @param array{page:int,text:string,isSparse:bool} $page
     * @return array{0:string,1:string}
     */
    private function autoPage(string $absPath, array $page, int $beUser): array
    {
        if ($page['isSparse']) {
            return [$this->visionExtractor->ocrPage($absPath, $page['page'], $beUser), 'vision'];
        }
        if ($this->looksTabular($page['text'])) {
            return [$this->layoutExtractor->extractPage($absPath, $page['page']), 'tables'];
        }

        return [$page['text'], 'text'];
    }

    /** Cheap table heuristic: 3+ lines with a run of 2+ spaces between non-space chars (column gutters). */
    private function looksTabular(string $text): bool
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $aligned = 0;
        foreach ($lines as $line) {
            if (preg_match('/\S {2,}\S/', $line) === 1) {
                $aligned++;
            }
        }

        return $aligned >= 3;
    }

    /**
     * @param array<string,bool> $tiers
     * @return list<string>
     */
    private function orderTiers(array $tiers): array
    {
        $ordered = [];
        foreach (['text', 'vision', 'tables'] as $tier) {
            if (isset($tiers[$tier])) {
                $ordered[] = $tier;
            }
        }

        return $ordered;
    }
}
