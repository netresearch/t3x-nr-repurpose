<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Generator\Support\InvalidLlmOutputException;
use Netresearch\NrRepurpose\Generator\Support\TextArtifact;
use Netresearch\NrRepurpose\Generator\Support\TextLabels;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Rendering\HtmlToPdfRendererInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Service\CallerSource;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * A printable handout of the source, one to two A4 pages: title, lead, sections with a
 * heading and short paragraphs, and a box of key facts, printed as a PDF. The
 * copy-ready text is the same content as plain text.
 */
class HandoutGenerator extends AbstractDocumentGenerator
{
    public const MAX_SECTIONS = 5;

    public const MAX_PARAGRAPHS = 2;

    public const MAX_FACTS = 6;

    public function __construct(
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
        LoggerInterface $logger,
        CompletionServiceInterface $completion,
        HtmlToPdfRendererInterface $pdfRenderer,
        JobFileStorage $fileStorage,
        ViewFactoryInterface $viewFactory,
        private readonly TextLabels $labels,
    ) {
        parent::__construct($jobs, $budget, $logger, $completion, $pdfRenderer, $fileStorage, $viewFactory);
    }

    protected function artifactType(): ArtifactType
    {
        return ArtifactType::Handout;
    }

    protected function wantColumn(): string
    {
        return 'want_handout';
    }

    protected function label(): string
    {
        return 'Handout';
    }

    protected function operation(): string
    {
        return CallerSource::GENERATE_HANDOUT;
    }

    protected function role(): string
    {
        return 'You are an editor writing a printable handout.';
    }

    protected function taskInstruction(GenerationContext $ctx): string
    {
        return sprintf(
            'Write a handout of one to two printed pages about the source material: a title, a lead of two or '
            . 'three sentences, 2 to %1$d sections with a heading and one or %2$d short paragraphs each, and 3 to '
            . '%3$d key facts of one sentence. Output ONLY JSON {"title":"...","lead":"...","sections":'
            . '[{"heading":"...","paragraphs":["..."]}],"keyFacts":["..."]}.',
            self::MAX_SECTIONS,
            self::MAX_PARAGRAPHS,
            self::MAX_FACTS,
        );
    }

    public function responseSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['title', 'lead', 'sections', 'keyFacts'],
            'additionalProperties' => false,
            'properties'           => [
                'title'    => ['type' => 'string', 'minLength' => 1],
                'lead'     => ['type' => 'string', 'minLength' => 1],
                'sections' => [
                    'type'     => 'array',
                    'minItems' => 1,
                    'items'    => [
                        'type'                 => 'object',
                        'required'             => ['heading', 'paragraphs'],
                        'additionalProperties' => false,
                        'properties'           => [
                            'heading'    => ['type' => 'string', 'minLength' => 1],
                            'paragraphs' => [
                                'type'     => 'array',
                                'minItems' => 1,
                                'items'    => ['type' => 'string', 'minLength' => 1],
                            ],
                        ],
                    ],
                ],
                'keyFacts' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string', 'minLength' => 1],
                ],
            ],
        ];
    }

    protected function parse(array $data, GenerationContext $ctx): array
    {
        $title = $this->stringField($data['title'] ?? null);
        $lead  = $this->stringField($data['lead'] ?? null);
        if ($title === null || $lead === null) {
            throw new InvalidLlmOutputException('the handout has no title or no lead', 1790000303);
        }

        $sections = [];
        foreach (is_array($data['sections'] ?? null) ? $data['sections'] : [] as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $heading    = $this->stringField($raw['heading'] ?? null);
            $paragraphs = array_slice($this->stringList($raw['paragraphs'] ?? null), 0, self::MAX_PARAGRAPHS);
            if ($heading === null || $paragraphs === []) {
                continue;
            }

            $sections[] = ['heading' => $heading, 'paragraphs' => $paragraphs];
            if (count($sections) >= self::MAX_SECTIONS) {
                break;
            }
        }

        if ($sections === []) {
            throw new InvalidLlmOutputException('the handout has no section with a heading and text', 1790000304);
        }

        $facts = array_slice($this->stringList($data['keyFacts'] ?? null), 0, self::MAX_FACTS);

        $text = [$title, $lead];
        foreach ($sections as $section) {
            $text[] = $section['heading'] . "\n" . implode("\n\n", $section['paragraphs']);
        }

        if ($facts !== []) {
            $text[] = '- ' . implode("\n- ", $facts);
        }

        return [new TextArtifact('default', implode("\n\n", $text), [
            'title'    => $title,
            'lead'     => $lead,
            'sections' => $sections,
            'keyFacts' => $facts,
        ])];
    }

    protected function templateArea(): string
    {
        return 'Handout';
    }

    protected function templateLabels(string $language): array
    {
        return ['keyFacts' => $this->labels->get('text.handout.keyFacts', $language)];
    }

    protected function fileName(): string
    {
        return 'handout.pdf';
    }

    protected function viewportWidth(): int
    {
        // A4 at 96 CSS pixels per inch.
        return 794;
    }
}
