<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion\Poppler;

/**
 * Seam over the Poppler CLI binaries (pdftoppm, pdftotext). Real implementation uses
 * Symfony Process; unit tests fake it so PdfVisionExtractor/PdfLayoutExtractor stay pure.
 */
interface PopplerRunnerInterface
{
    /**
     * Rasterize one 1-based page of $absPdfPath to PNG and return the raw PNG bytes.
     * (pdftoppm -png -r <dpi> -f <page> -l <page> -singlefile)
     */
    public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string;

    /**
     * Extract one 1-based page preserving columns/tables as plain text.
     * (pdftotext -layout -f <page> -l <page> -enc UTF-8 -nopgbrk -q ... -)
     */
    public function extractLayout(string $absPdfPath, int $page): string;
}
