<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * Tier 1 — embedded PDF text via smalot/pdfparser, per page, with near-empty (sparse)
 * detection so the auto dispatcher can escalate scanned pages to Vision OCR.
 */
class PdfTextExtractor
{
    /** Minimum non-whitespace chars before a page counts as "has text". */
    private const MIN_CHARS_PER_PAGE = 80;

    /**
     * @return list<array{page:int, text:string, isSparse:bool}>
     * @throws IngestionException on a missing, encrypted or unparseable PDF
     */
    public function extract(string $absPath): array
    {
        if (!is_file($absPath)) {
            throw new IngestionException('PDF file not found: ' . $absPath, 1749379420);
        }

        $config = new Config();
        // Mitigates known false-positive "Secured pdf file" detection (smalot issues #488/#743).
        $config->setIgnoreEncryption(true);

        $parser = new Parser([], $config);
        try {
            $document = $parser->parseFile($absPath);
            $pageObjects = $document->getPages();
        } catch (\Throwable $e) {
            // smalot throws \Exception('Secured pdf file are currently not supported.') on real encryption.
            throw new IngestionException(
                'PDF could not be parsed (possibly encrypted): ' . $e->getMessage(),
                1749379421,
                $e,
            );
        }

        $pages = [];
        foreach ($pageObjects as $i => $page) {
            $text = trim($page->getText());
            $density = strlen(preg_replace('/\s+/', '', $text) ?? '');
            $pages[] = [
                'page' => $i + 1,
                'text' => $text,
                'isSparse' => $density < self::MIN_CHARS_PER_PAGE,
            ];
        }

        if ($pages === []) {
            throw new IngestionException('PDF has no pages: ' . $absPath, 1749379422);
        }

        return $pages;
    }
}
