<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;

/**
 * Tier 2 — renders a PDF page to PNG (Poppler) and OCRs it through nr-llm Vision.
 * Used by the auto dispatcher for scanned/image-only pages and by forced `vision` mode.
 */
final class PdfVisionExtractor
{
    private const OCR_PROMPT = 'Transcribe ALL text in this page image verbatim, '
        . 'preserving reading order and line breaks. Output plain text only, no commentary.';

    private const OCR_MAX_TOKENS = 2000;

    public function __construct(
        private readonly PopplerRunnerInterface $poppler,
        private readonly VisionServiceInterface $vision,
    ) {}

    /**
     * OCR a single 1-based page of $absPdfPath. $beUser>0 enables the nr-llm budget guard
     * on the Vision call; pass 0 to skip (CLI/anonymous).
     */
    public function ocrPage(string $absPdfPath, int $page, int $beUser, int $dpi = 200): string
    {
        $png = $this->poppler->rasterizePage($absPdfPath, $page, $dpi);
        $dataUri = 'data:image/png;base64,' . base64_encode($png);

        $options = (new VisionOptions())->withMaxTokens(self::OCR_MAX_TOKENS);
        if ($beUser > 0) {
            $options = $options->withBeUserUid($beUser);
        }

        // VisionService::analyzeImage() validates data:image/png;base64,... URIs natively.
        $result = $this->vision->analyzeImage($dataUri, self::OCR_PROMPT, $options);

        return is_array($result) ? implode("\n", $result) : $result;
    }
}
