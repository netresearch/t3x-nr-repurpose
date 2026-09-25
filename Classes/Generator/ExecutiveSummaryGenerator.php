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
 * A 5–8 sentence summary for decision makers, key facts first. The model answers with a
 * list of sentences so the count is checkable: more than eight are cut to the first
 * eight (the key facts come first by instruction). Fewer than five are kept — a thin
 * source may not carry five sentences' worth of facts, and padding would invent them.
 */
final class ExecutiveSummaryGenerator extends AbstractTextGenerator
{
    public const MAX_SENTENCES = 8;

    protected function artifactType(): ArtifactType
    {
        return ArtifactType::ExecutiveSummary;
    }

    protected function wantColumn(): string
    {
        return 'want_exec_summary';
    }

    protected function label(): string
    {
        return 'Executive summary';
    }

    protected function operation(): string
    {
        return CallerSource::GENERATE_EXEC_SUMMARY;
    }

    protected function systemPrompt(): string
    {
        return 'You are an editor writing executive summaries for decision makers. Output ONLY valid JSON.';
    }

    protected function taskInstruction(GenerationContext $ctx): string
    {
        return 'Write an executive summary of this content for decision makers in 5 to 8 sentences. '
            . 'Put the key facts, numbers and the conclusion first, then context. One sentence per list '
            . 'entry. Output ONLY JSON {"sentences":["..."]}.';
    }

    public function responseSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['sentences'],
            'additionalProperties' => false,
            'properties'           => [
                'sentences' => [
                    'type'     => 'array',
                    'minItems' => 1,
                    'items'    => ['type' => 'string', 'minLength' => 1],
                ],
            ],
        ];
    }

    protected function parse(array $data, GenerationContext $ctx): array
    {
        $sentences = array_slice($this->stringList($data['sentences'] ?? null), 0, self::MAX_SENTENCES);
        if ($sentences === []) {
            throw new InvalidLlmOutputException('the answer contains no summary sentences', 1790000101);
        }

        return [new TextArtifact('default', implode(' ', $sentences), ['sentences' => $sentences])];
    }
}
