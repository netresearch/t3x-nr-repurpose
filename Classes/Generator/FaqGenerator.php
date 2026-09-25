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
 * 5–10 question/answer pairs answered only from the source. Stored structured
 * (`content.faq` = list of {question, answer}) and additionally as a schema.org FAQPage
 * JSON-LD document (`content.jsonLd`) an editor can paste into a page. A pair missing its
 * question or answer is dropped; more than ten pairs are cut to ten. Fewer than five are
 * kept for the same reason as in the executive summary: a thin source must not be padded.
 */
final class FaqGenerator extends AbstractTextGenerator
{
    public const MAX_PAIRS = 10;

    protected function artifactType(): ArtifactType
    {
        return ArtifactType::Faq;
    }

    protected function wantColumn(): string
    {
        return 'want_faq';
    }

    protected function label(): string
    {
        return 'FAQ';
    }

    protected function operation(): string
    {
        return CallerSource::GENERATE_FAQ;
    }

    protected function systemPrompt(): string
    {
        return 'You are an editor writing FAQ sections. Output ONLY valid JSON.';
    }

    protected function taskInstruction(GenerationContext $ctx): string
    {
        return 'Write 5 to 10 frequently asked questions a reader of this content would have, each with a '
            . 'concise answer of one to three sentences. Answer only from the content above; skip a question '
            . 'the content does not answer. Output ONLY JSON {"faq":[{"question":"...","answer":"..."}]}.';
    }

    public function responseSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['faq'],
            'additionalProperties' => false,
            'properties'           => [
                'faq' => [
                    'type'     => 'array',
                    'minItems' => 1,
                    'items'    => [
                        'type'                 => 'object',
                        'required'             => ['question', 'answer'],
                        'additionalProperties' => false,
                        'properties'           => [
                            'question' => ['type' => 'string', 'minLength' => 1],
                            'answer'   => ['type' => 'string', 'minLength' => 1],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function parse(array $data, GenerationContext $ctx): array
    {
        $pairs = [];
        foreach (is_array($data['faq'] ?? null) ? $data['faq'] : [] as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $question = $this->stringField($raw['question'] ?? null);
            $answer   = $this->stringField($raw['answer'] ?? null);
            if ($question === null || $answer === null) {
                continue;
            }

            $pairs[] = ['question' => $question, 'answer' => $answer];
            if (count($pairs) >= self::MAX_PAIRS) {
                break;
            }
        }

        if ($pairs === []) {
            throw new InvalidLlmOutputException('the answer contains no complete question/answer pair', 1790000201);
        }

        $plainText = implode("\n\n", array_map(
            static fn (array $pair): string => sprintf("Q: %s\nA: %s", $pair['question'], $pair['answer']),
            $pairs,
        ));

        return [new TextArtifact('default', $plainText, ['faq' => $pairs, 'jsonLd' => $this->jsonLd($pairs)])];
    }

    /**
     * schema.org FAQPage JSON-LD for the pairs. JSON_HEX_TAG escapes "<" and ">" so the
     * document stays inert when pasted into a <script type="application/ld+json"> block.
     *
     * @param list<array{question: string, answer: string}> $pairs
     */
    public function jsonLd(array $pairs): string
    {
        return json_encode(
            [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => array_map(
                    static fn (array $pair): array => [
                        '@type'          => 'Question',
                        'name'           => $pair['question'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $pair['answer']],
                    ],
                    $pairs,
                ),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG,
        );
    }
}
