<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrRepurpose\Generator\Support\TextArtifact;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Provenance\AiProvenance;
use Netresearch\NrRepurpose\Rendering\HtmlToPdfRendererInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * A text format that is also delivered as a PDF (slide deck, handout): the structured
 * answer is written like any text format, then rendered through a branded template
 * (Resources/Private/Templates/Generated/<area>/) to a PDF by Chromium and stored in
 * FAL, AI-labelled (ADR-005). The template's own CSS sets page size and breaks.
 *
 * The PDF render is part of the result: when it fails, the row fails, because a slide
 * deck without its slides is not the format the editor asked for.
 */
abstract class AbstractDocumentGenerator extends AbstractTextGenerator
{
    use RendersThemeTemplates;

    public function __construct(
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
        LoggerInterface $logger,
        CompletionServiceInterface $completion,
        private readonly HtmlToPdfRendererInterface $pdfRenderer,
        private readonly JobFileStorage $fileStorage,
        private readonly ViewFactoryInterface $viewFactory,
    ) {
        parent::__construct($jobs, $budget, $logger, $completion);
    }

    protected function viewFactory(): ViewFactoryInterface
    {
        return $this->viewFactory;
    }

    /** Template folder below Resources/Private/Templates/Generated/. */
    abstract protected function templateArea(): string;

    /** File name of the stored PDF (a random suffix is added on storing). */
    abstract protected function fileName(): string;

    /** Layout width in CSS pixels the template is designed for. */
    abstract protected function viewportWidth(): int;

    /**
     * Fixed texts the template prints, in the language the content is written in.
     *
     * @return array<string, string>
     */
    protected function templateLabels(string $language): array
    {
        return [];
    }

    /**
     * The document's HTML from its branded template; seam isolated for unit testing.
     *
     * @param array<string, mixed> $variables
     */
    protected function renderDocumentHtml(string $theme, array $variables): string
    {
        return $this->renderTemplate($this->templateArea(), $theme, $variables);
    }

    protected function fileFields(TextArtifact $artifact, GenerationContext $ctx, AiProvenance $provenance): array
    {
        $html = $this->renderDocumentHtml($ctx->theme, [
            'content'     => $artifact->content,
            'sourceLabel' => $ctx->document->sourceLabel,
            'language'    => $ctx->brief->language,
            'labels'      => $this->templateLabels($ctx->brief->language),
        ]);

        $ctx->progress?->step($this->label() . ': rendering PDF', 0.7);
        $pdfPath = $this->pdfRenderer->renderPdf($html, $this->viewportWidth());
        try {
            $file = $this->fileStorage->store((string) file_get_contents($pdfPath), $this->fileName(), $provenance);
        } finally {
            // Also when labelling or the FAL write fails: the worker runs long.
            if (is_file($pdfPath)) {
                // $pdfPath is the renderer's own temp file (random name in its output dir),
                // never user input.
                unlink($pdfPath); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }
        }

        return ['file_uid' => $file->getUid(), 'source_html' => $html];
    }
}
