<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Generator\Support\InvalidLlmOutputException;
use Netresearch\NrRepurpose\Generator\Support\TextArtifact;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Service\CallerSource;

/**
 * A presentation of the source: a title slide, content slides with a heading and bullet
 * points, and a closing slide with the takeaway, printed as a 16:9 PDF (1920x1080 CSS
 * pixels per page). The copy-ready text is the outline.
 *
 * Headings, bullets and slide counts are capped here, because a slide has a fixed size:
 * text beyond the caps would run off the page.
 */
class SlideDeckGenerator extends AbstractDocumentGenerator
{
    public const MAX_SLIDES = 10;

    public const MAX_BULLETS = 5;

    public const MAX_HEADING_CHARS = 80;

    public const MAX_BULLET_CHARS = 140;

    protected function artifactType(): ArtifactType
    {
        return ArtifactType::SlideDeck;
    }

    protected function label(): string
    {
        return 'Slide deck';
    }

    protected function operation(): string
    {
        return CallerSource::GENERATE_SLIDE_DECK;
    }

    protected function role(): string
    {
        return 'You are an editor writing presentation slides.';
    }

    protected function taskInstruction(GenerationContext $ctx): string
    {
        return sprintf(
            'Write a slide deck that presents the source material: a deck title with a one-line subtitle, '
            . '3 to %1$d content slides, each with a short heading of at most %2$d characters and 2 to %3$d '
            . 'bullet points of at most %4$d characters, and a closing takeaway of one sentence. Output ONLY '
            . 'JSON {"title":"...","subtitle":"...","slides":[{"heading":"...","bullets":["..."]}],"takeaway":"..."}.',
            self::MAX_SLIDES - 2,
            self::MAX_HEADING_CHARS,
            self::MAX_BULLETS,
            self::MAX_BULLET_CHARS,
        );
    }

    public function responseSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['title', 'subtitle', 'slides', 'takeaway'],
            'additionalProperties' => false,
            'properties'           => [
                'title'    => ['type' => 'string', 'minLength' => 1],
                'subtitle' => ['type' => 'string'],
                'slides'   => [
                    'type'     => 'array',
                    'minItems' => 1,
                    'items'    => [
                        'type'                 => 'object',
                        'required'             => ['heading', 'bullets'],
                        'additionalProperties' => false,
                        'properties'           => [
                            'heading' => ['type' => 'string', 'minLength' => 1],
                            'bullets' => [
                                'type'     => 'array',
                                'minItems' => 1,
                                'items'    => ['type' => 'string', 'minLength' => 1],
                            ],
                        ],
                    ],
                ],
                'takeaway' => ['type' => 'string'],
            ],
        ];
    }

    protected function parse(array $data, GenerationContext $ctx): array
    {
        $title = $this->stringField($data['title'] ?? null);
        if ($title === null) {
            throw new InvalidLlmOutputException('the slide deck has no title', 1790000301);
        }

        $slides = [];
        foreach (is_array($data['slides'] ?? null) ? $data['slides'] : [] as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $heading = $this->stringField($raw['heading'] ?? null);
            $bullets = array_slice($this->stringList($raw['bullets'] ?? null), 0, self::MAX_BULLETS);
            if ($heading === null || $bullets === []) {
                continue;
            }

            $slides[] = [
                'heading' => mb_substr($heading, 0, self::MAX_HEADING_CHARS),
                'bullets' => array_map(static fn (string $bullet): string => mb_substr($bullet, 0, self::MAX_BULLET_CHARS), $bullets),
            ];
            // Title and closing slide take two of the pages.
            if (count($slides) >= self::MAX_SLIDES - 2) {
                break;
            }
        }

        if ($slides === []) {
            throw new InvalidLlmOutputException('the slide deck has no slide with a heading and bullet points', 1790000302);
        }

        $subtitle = $this->stringField($data['subtitle'] ?? null) ?? '';
        $takeaway = $this->stringField($data['takeaway'] ?? null) ?? '';

        $outline = [$title . ($subtitle !== '' ? "\n" . $subtitle : '')];
        foreach ($slides as $i => $slide) {
            $outline[] = sprintf("%d. %s\n- %s", $i + 1, $slide['heading'], implode("\n- ", $slide['bullets']));
        }

        if ($takeaway !== '') {
            $outline[] = $takeaway;
        }

        return [new TextArtifact('default', implode("\n\n", $outline), [
            'title'    => $title,
            'subtitle' => $subtitle,
            'slides'   => $slides,
            'takeaway' => $takeaway,
        ])];
    }

    protected function templateArea(): string
    {
        return 'SlideDeck';
    }

    protected function fileName(): string
    {
        return 'slide-deck.pdf';
    }

    protected function viewportWidth(): int
    {
        return 1920;
    }
}
