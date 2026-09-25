<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Generator\Support\TextArtifact;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Service\CallerSource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared flow of the text formats (executive summary, FAQ, social posts, newsletter):
 * ONE structured nr-llm completion per format, validated twice — by nr-llm against the
 * format's JSON schema (completeStructured(), one repair round-trip), then by the
 * generator's own parse(), which enforces what a schema cannot express (limits cut at a
 * sentence boundary, caps, the plain-text rendering). See ADR-004.
 *
 * Every row stores the plain text in `script_text` and the structured content in
 * `metadata.content`. A text format makes no Specialized call (no TTS, no image), so it
 * needs no capability grant — the completion is budget-guarded by nr-llm's middleware
 * via ChatOptions->beUserUid, exactly like the story copy.
 *
 * Any failure — provider error, schema mismatch after the repair round, or a parse()
 * rejection — records one failed row with a readable reason and returns false; sibling
 * generators keep running.
 */
abstract class AbstractTextGenerator extends AbstractGenerator
{
    private const PLANNED_COST = 0.01;

    public function __construct(
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
        LoggerInterface $logger,
        // Must stay named $completion: Services.yaml binds that name to the
        // ConfiguredCompletionService (the nr_repurpose_text configuration).
        protected readonly CompletionServiceInterface $completion,
    ) {
        parent::__construct($jobs, $budget, $logger);
    }

    /** The artifact type this generator produces. */
    abstract protected function artifactType(): ArtifactType;

    /** The job-row flag that requests this format (e.g. `want_faq`). */
    abstract protected function wantColumn(): string;

    /** Human-readable format name, used in progress steps and error messages. */
    abstract protected function label(): string;

    /** The CallerSource operation this format's completion is attributed to. */
    abstract protected function operation(): string;

    abstract protected function systemPrompt(): string;

    /** The format-specific instruction appended to the shared source block. */
    abstract protected function taskInstruction(GenerationContext $ctx): string;

    /**
     * JSON schema of the expected answer, inside nr-llm's strict subset (ADR-126).
     * Public so a test can pre-flight it against nr-llm's validator.
     *
     * @return array<string, mixed>
     */
    abstract public function responseSchema(): array;

    /**
     * Turn the schema-valid answer into the rows to store. Throws
     * InvalidLlmOutputException with an editor-readable reason when the answer is
     * unusable despite matching the schema.
     *
     * @param array<string, mixed> $data
     *
     * @return non-empty-list<TextArtifact>
     */
    abstract protected function parse(array $data, GenerationContext $ctx): array;

    public function supports(GenerationContext $ctx): bool
    {
        return (bool) ($ctx->jobRow[$this->wantColumn()] ?? false);
    }

    public function generate(GenerationContext $ctx): bool
    {
        $jobUid = $ctx->jobUid();
        $prompt = $this->userPrompt($ctx);

        try {
            $ctx->progress?->step($this->label() . ': writing text', 0.1);
            $options = (new ChatOptions(
                temperature: 0.4,
                responseFormat: 'json',
                systemPrompt: $this->systemPrompt(),
                beUserUid: $ctx->beUser,
                plannedCost: self::PLANNED_COST,
            ))->withCallerSource(CallerSource::EXTENSION, $this->operation());

            $artifacts = $this->parse(
                $this->completion->completeStructured($prompt, $this->responseSchema(), $options),
                $ctx,
            );
        } catch (Throwable $e) {
            $artifactUid = $this->jobs->insertArtifact($jobUid, $this->artifactType(), 'default', 0, ArtifactStatus::Pending);
            $this->failArtifact($artifactUid, $jobUid, sprintf('%s generation error: %s', $this->label(), $e->getMessage()));

            return false;
        }

        // One row per result; a failed write fails only that row, like a story slide.
        $prompts = $this->promptsMetadata(system: $this->systemPrompt(), user: $prompt);
        $ok      = false;
        foreach ($artifacts as $artifact) {
            $artifactUid = $this->jobs->insertArtifact($jobUid, $this->artifactType(), $artifact->variant, 0, ArtifactStatus::Pending);
            try {
                $this->jobs->updateArtifact($artifactUid, [
                    'script_text' => $artifact->plainText,
                    'metadata'    => json_encode(['content' => $artifact->content, 'prompts' => $prompts], JSON_THROW_ON_ERROR),
                    'status'      => ArtifactStatus::Done->value,
                ]);
                $ok = true;
            } catch (Throwable $e) {
                $this->failArtifact($artifactUid, $jobUid, sprintf('%s (%s) storage error: %s', $this->label(), $artifact->variant, $e->getMessage()));
            }
        }

        return $ok;
    }

    /**
     * The user prompt this generator passes to completeStructured() — stored in the
     * metadata (prompts.user), so it is built in one place only. It is not the full text
     * the provider receives: nr-llm appends the JSON-schema instruction (and, on a repair
     * round, the rejected answer). The source block is the shared ContentBrief; the output
     * language is the detected source language, as for every other artifact.
     */
    protected function userPrompt(GenerationContext $ctx): string
    {
        $brief    = $ctx->brief;
        $sections = array_map(
            static fn (array $section): string => sprintf("## %s\n%s", $section['heading'], $section['body']),
            $brief->sections,
        );

        $prompt = sprintf(
            "Title: %s\nSummary: %s\nAudience: %s\nKey points:\n- %s\n\nSections:\n%s\n\nSource: %s\n\n%s\n\n"
            . 'Use only facts stated in the content above — no outside knowledge, no invented numbers. '
            . 'Write in language code "%s".',
            $brief->title,
            $brief->summary,
            $brief->audience,
            implode("\n- ", $brief->keyPoints),
            implode("\n\n", $sections),
            $ctx->document->sourceLabel,
            $this->taskInstruction($ctx),
            $brief->language,
        );
        if ($ctx->snippets->textSections !== '') {
            $prompt .= "\n\n" . $ctx->snippets->textSections;
        }

        return $prompt;
    }

    /**
     * A trimmed non-empty string from a decoded answer field, or null.
     */
    protected function stringField(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * The trimmed non-empty strings of a decoded list field, in order.
     *
     * @return list<string>
     */
    protected function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $string = $this->stringField($item);
            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return $strings;
    }
}
