<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

/**
 * The identity this extension puts on every nr-llm call (nr-llm ADR-177):
 * `AbstractOptions::withCallerSource(self::EXTENSION, self::<OPERATION>)` persists
 * source_extension / source_operation on the telemetry row, which is what nr-llm's
 * Analytics module groups usage and cost by. Without it the calls land in
 * "Unattributed".
 *
 * One operation per pipeline step, not one per extension: a repurpose job makes
 * several LLM calls with very different cost profiles (document analysis, podcast
 * script, diagram body, story copy, page OCR), and the per-step split is what makes
 * the breakdown readable.
 */
final class CallerSource
{
    /** Extension key — composer.json `extra.typo3/cms.extension-key`. */
    public const EXTENSION = 'nr_repurpose';

    /** DocumentAnalyzer synthesis call (and its corrective retry) producing the ContentBrief. */
    public const ANALYZE_DOCUMENT = 'analyzeDocument';

    /** DocumentAnalyzer map step: one call per chunk of an oversized document. */
    public const ANALYZE_DOCUMENT_CHUNK = 'analyzeDocumentChunk';

    /** PdfVisionExtractor OCR of one rasterized PDF page. */
    public const EXTRACT_PDF_VISION = 'extractPdfVision';

    /** PodcastGenerator dialogue script (two-host and persona shape). */
    public const GENERATE_PODCAST = 'generatePodcast';

    /** SchaubildGenerator diagram body HTML. */
    public const GENERATE_DIAGRAM = 'generateDiagram';

    /** StoryGenerator carousel copy. */
    public const GENERATE_STORY = 'generateStory';
}
