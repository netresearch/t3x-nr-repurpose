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
use Netresearch\NrRepurpose\Service\CallerSource;
use Psr\Log\LoggerInterface;

/**
 * A newsletter text: subject line, preheader, body in plain paragraphs and exactly one
 * call to action. "Exactly one" is structural — the answer has a single `callToAction`
 * string, not a list. Every part is required; a missing one fails the artifact.
 */
final class NewsletterGenerator extends AbstractTextGenerator
{
    public function __construct(
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
        LoggerInterface $logger,
        CompletionServiceInterface $completion,
        private readonly TextLabels $labels,
    ) {
        parent::__construct($jobs, $budget, $logger, $completion);
    }

    protected function artifactType(): ArtifactType
    {
        return ArtifactType::Newsletter;
    }

    protected function wantColumn(): string
    {
        return 'want_newsletter';
    }

    protected function label(): string
    {
        return 'Newsletter';
    }

    protected function operation(): string
    {
        return CallerSource::GENERATE_NEWSLETTER;
    }

    protected function role(): string
    {
        return 'You are an editor writing e-mail newsletters.';
    }

    protected function taskInstruction(GenerationContext $ctx): string
    {
        return 'Write a newsletter text about the source material: a subject line of at most 60 characters, a '
            . 'preheader of at most 100 characters that complements the subject, a body of 2 to 5 plain '
            . 'paragraphs (no headings, no lists, no markup), and exactly one call to action that invites '
            . 'the reader to the source. The body itself contains no call to action. Output ONLY JSON '
            . '{"subject":"...","preheader":"...","paragraphs":["..."],"callToAction":"..."}.';
    }

    public function responseSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['subject', 'preheader', 'paragraphs', 'callToAction'],
            'additionalProperties' => false,
            'properties'           => [
                'subject'    => ['type' => 'string', 'minLength' => 1],
                'preheader'  => ['type' => 'string', 'minLength' => 1],
                'paragraphs' => [
                    'type'     => 'array',
                    'minItems' => 1,
                    'items'    => ['type' => 'string', 'minLength' => 1],
                ],
                'callToAction' => ['type' => 'string', 'minLength' => 1],
            ],
        ];
    }

    protected function parse(array $data, GenerationContext $ctx): array
    {
        $subject      = $this->stringField($data['subject'] ?? null);
        $preheader    = $this->stringField($data['preheader'] ?? null);
        $paragraphs   = $this->stringList($data['paragraphs'] ?? null);
        $callToAction = $this->stringField($data['callToAction'] ?? null);

        $missing = array_keys(array_filter(
            [
                'subject'      => $subject === null,
                'preheader'    => $preheader === null,
                'paragraphs'   => $paragraphs === [],
                'callToAction' => $callToAction === null,
            ],
        ));
        if ($subject === null || $preheader === null || $paragraphs === [] || $callToAction === null) {
            throw new InvalidLlmOutputException(
                sprintf('the answer lacks %s', implode(', ', $missing)),
                1790000401,
            );
        }

        // Labels in the language the newsletter is written in, not the editor's.
        $plainText = sprintf(
            "%s: %s\n%s: %s\n\n%s\n\n%s",
            $this->labels->get('text.newsletter.subject', $ctx->brief->language),
            $subject,
            $this->labels->get('text.newsletter.preheader', $ctx->brief->language),
            $preheader,
            implode("\n\n", $paragraphs),
            $callToAction,
        );

        return [new TextArtifact('default', $plainText, [
            'subject'      => $subject,
            'preheader'    => $preheader,
            'paragraphs'   => $paragraphs,
            'callToAction' => $callToAction,
        ])];
    }
}
