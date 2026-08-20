<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;
use Netresearch\NrRepurpose\Service\CallerSource;

/**
 * Tier 2 — renders a PDF page to PNG (Poppler) and OCRs it through nr-llm Vision.
 * Used by the auto dispatcher for scanned/image-only pages and by forced `vision` mode.
 *
 * The Vision options name this extension and the step (CallerSource) like every other
 * nr-llm call here. Note that nr-llm 0.31.1's VisionService::analyzeImageFull() rebuilds
 * the VisionOptions field by field and does not copy the caller source, so the annotation
 * does not reach the telemetry row yet — netresearch/t3x-nr-llm#843.
 */
class PdfVisionExtractor
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
        $png     = $this->poppler->rasterizePage($absPdfPath, $page, $dpi);
        $dataUri = 'data:image/png;base64,' . base64_encode($png);

        $options = (new VisionOptions())
            ->withMaxTokens(self::OCR_MAX_TOKENS)
            ->withCallerSource(CallerSource::EXTENSION, CallerSource::EXTRACT_PDF_VISION);
        if ($beUser > 0) {
            $options = $options->withBeUserUid($beUser);
        }

        // VisionService::analyzeImage() validates data:image/png;base64,... URIs natively.
        $result = $this->vision->analyzeImage($dataUri, self::OCR_PROMPT, $options);

        return is_array($result) ? implode("\n", $result) : $result;
    }
}
