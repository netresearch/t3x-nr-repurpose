<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

/**
 * Renders an HTML document to a PDF file. Page size, margins and page breaks come from
 * the document's own CSS (`@page`, `break-after`), so one call covers an A4 handout
 * and a 16:9 slide deck alike.
 */
interface HtmlToPdfRendererInterface
{
    /**
     * @param int $viewportWidth layout width in CSS pixels before printing
     *
     * @return string absolute path of the written PDF
     *
     * @throws RenderingException
     */
    public function renderPdf(string $html, int $viewportWidth): string;
}
