<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Netresearch\NrRepurpose\Domain\Enum\PdfMode;
use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolver;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolverInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Single ingestion entry point. URL sources go through WebPageFetcher; PDF sources are
 * resolved to a local file and run through a per-page tier dispatcher honoring pdf_mode:
 *   - auto:   tier 1 (embedded text); sparse page -> tier 2 (Vision OCR); tabular page -> tier 3 (layout)
 *   - text:   tier 1 for every page
 *   - vision: tier 2 for every page
 *   - tables: tier 3 for every page.
 *
 * Tier 2 is an nr-llm Vision call and needs the job owner's `nrrepurpose:generate_vision`
 * grant, like the AI imagery of the generators. Without it a page that would go to tier 2
 * keeps its embedded text (tier 1): a denied capability fails only the part that needs
 * it. When no page has text left, the ingestion fails naming the missing option.
 *
 * A PDF with more pages than the extension setting `maxPdfPages` (default 200) fails
 * after parsing and before any page's text is extracted or read with OCR or the layout
 * tier, so one job cannot run an unbounded number of text, poppler and Vision calls.
 */
final readonly class SourceIngestionService implements SourceIngestionServiceInterface
{
    /** Internal tier label: tier 2 was wanted but not granted, the page kept its text. */
    private const TIER_VISION_DENIED = 'vision-denied';

    /** Pages of one PDF when the extension setting `maxPdfPages` is unset or not a positive number. */
    public const DEFAULT_MAX_PDF_PAGES = 200;

    public function __construct(
        private WebPageFetcher $webPageFetcher,
        private PdfFileResolver $pdfFileResolver,
        private PdfTextExtractor $textExtractor,
        private PdfVisionExtractor $visionExtractor,
        private PdfLayoutExtractor $layoutExtractor,
        private CapabilityGrantResolverInterface $grantResolver,
        private LoggerInterface $logger,
        private ?ExtensionConfiguration $extensionConfiguration = null,
    ) {}

    public function ingest(JobSnapshot $job): SourceDocument
    {
        return match ($job->sourceType) {
            SourceType::Url                        => $this->ingestUrl($job->sourceValue),
            SourceType::PdfUrl, SourceType::PdfFal => $this->ingestPdf($job),
        };
    }

    private function ingestUrl(string $url): SourceDocument
    {
        if (trim($url) === '') {
            throw new IngestionException('url job has an empty source_value', 1749379451);
        }

        return $this->webPageFetcher->fetch($url);
    }

    private function ingestPdf(JobSnapshot $job): SourceDocument
    {
        $absPath = $this->pdfFileResolver->resolve($job);
        try {
            return $this->readPdf($absPath, $job->pdfMode, $job->beUser);
        } catch (Throwable $e) {
            // The exception message becomes the job's error, which every module user
            // sees, so it never carries the server path; the path goes to the log.
            $this->logger->error('PDF ingestion failed', [
                'job'       => $job->uid,
                'path'      => $absPath,
                'exception' => $e,
            ]);

            throw $e;
        } finally {
            // A temp copy (a pdf_url download, a pdf_fal file from a non-local driver)
            // goes once it is read, also when reading failed.
            $this->pdfFileResolver->release($absPath);
        }
    }

    private function readPdf(string $absPath, PdfMode $mode, int $beUser): SourceDocument
    {
        // The extractor refuses an oversized PDF before reading any page's text; the check
        // here holds for an extractor that does not apply the limit.
        $maxPages = $this->maxPdfPages();
        $pages    = $this->textExtractor->extract($absPath, $maxPages);
        if (count($pages) > $maxPages) {
            throw PdfTextExtractor::tooManyPages(count($pages), $maxPages);
        }

        // Only the modes that can reach tier 2 look the grant up. Resolved from the
        // job owner like the orchestrator does for the generators (0 grants nothing).
        $visionGranted = ($mode === PdfMode::Vision || $mode === PdfMode::Auto)
            && $this->grantResolver->resolve($beUser)->vision;

        $texts        = [];
        $tiers        = [];
        $visionDenied = false;
        foreach ($pages as $page) {
            [$text, $tier] = $this->extractPage($absPath, $page, $mode, $beUser, $visionGranted);
            if ($tier === self::TIER_VISION_DENIED) {
                $visionDenied = true;
                $tier         = 'text';
            }

            if (trim($text) !== '') {
                $texts[]      = $text;
                $tiers[$tier] = true;
            }
        }

        $body = trim(implode("\n\n", $texts));
        if ($body === '' && $visionDenied) {
            throw new IngestionException(
                sprintf(
                    'The PDF has no embedded text, and reading it with Vision OCR is not permitted: the job owner\'s backend groups do not grant "Generate AI imagery" (%s)',
                    CapabilityGrantResolver::PERMISSION_VISION,
                ),
                1749379453,
            );
        }

        if ($body === '') {
            throw new IngestionException('No text could be extracted from the PDF', 1749379452);
        }

        $meta = ['tiersUsed' => $this->orderTiers($tiers)];
        if ($visionDenied) {
            $meta['visionDenied'] = true;
        }

        return new SourceDocument(
            title: '',
            text: $body,
            sourceLabel: basename($absPath),
            pageCount: count($pages),
            languageHint: '',
            meta: $meta,
        );
    }

    /**
     * @param array{page:int,text:string,isSparse:bool} $page
     *
     * @return array{0:string,1:string} [pageText, tierLabel]
     */
    private function extractPage(string $absPath, array $page, PdfMode $mode, int $beUser, bool $visionGranted): array
    {
        return match ($mode) {
            PdfMode::Text   => [$page['text'], 'text'],
            PdfMode::Vision => $this->visionPage($absPath, $page, $beUser, $visionGranted),
            PdfMode::Tables => [$this->layoutExtractor->extractPage($absPath, $page['page']), 'tables'],
            PdfMode::Auto   => $this->autoPage($absPath, $page, $beUser, $visionGranted),
        };
    }

    /**
     * @param array{page:int,text:string,isSparse:bool} $page
     *
     * @return array{0:string,1:string}
     */
    private function visionPage(string $absPath, array $page, int $beUser, bool $visionGranted): array
    {
        if (!$visionGranted) {
            return [$page['text'], self::TIER_VISION_DENIED];
        }

        return [$this->visionExtractor->ocrPage($absPath, $page['page'], $beUser), 'vision'];
    }

    /**
     * @param array{page:int,text:string,isSparse:bool} $page
     *
     * @return array{0:string,1:string}
     */
    private function autoPage(string $absPath, array $page, int $beUser, bool $visionGranted): array
    {
        if ($page['isSparse']) {
            return $this->visionPage($absPath, $page, $beUser, $visionGranted);
        }

        if ($this->looksTabular($page['text'])) {
            return [$this->layoutExtractor->extractPage($absPath, $page['page']), 'tables'];
        }

        return [$page['text'], 'text'];
    }

    private function maxPdfPages(): int
    {
        try {
            $value = $this->extensionConfiguration?->get('nr_repurpose', 'maxPdfPages');
        } catch (Throwable) {
            // Not configured at all (an installation from before the setting existed).
            $value = null;
        }

        $pages = is_numeric($value) ? (int) $value : 0;

        return $pages > 0 ? $pages : self::DEFAULT_MAX_PDF_PAGES;
    }

    /** Cheap table heuristic: 3+ lines with a run of 2+ spaces between non-space chars (column gutters). */
    private function looksTabular(string $text): bool
    {
        $split   = preg_split('/\R/', $text);
        $lines   = $split === false ? [] : $split;
        $aligned = 0;
        foreach ($lines as $line) {
            if (preg_match('/\S {2,}\S/', $line) === 1) {
                ++$aligned;
            }
        }

        return $aligned >= 3;
    }

    /**
     * @param array<string,bool> $tiers
     *
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
