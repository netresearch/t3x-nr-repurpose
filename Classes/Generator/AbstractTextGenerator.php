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
use Netresearch\NrRepurpose\Pipeline\SourceMaterial;
use Netresearch\NrRepurpose\Provenance\DigitalSourceType;
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

    /** The role line that opens the system prompt ("You are an editor writing …"). */
    abstract protected function role(): string;

    /** The format-specific task; part of the system prompt, never of the user prompt. */
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
        $system = $this->systemPrompt($ctx);
        $prompt = $this->userPrompt($ctx);

        try {
            $ctx->progress?->step($this->label() . ': writing text', 0.1);
            $options = (new ChatOptions(
                temperature: 0.4,
                responseFormat: 'json',
                systemPrompt: $system,
                beUserUid: $ctx->beUser,
                plannedCost: self::PLANNED_COST,
            ))->withCallerSource(CallerSource::EXTENSION, $this->operation());

            $artifacts = $this->withClosingLine(
                $this->parse(
                    $this->completion->completeStructured($prompt, $this->responseSchema(), $options),
                    $ctx,
                ),
                $ctx->aiLabel->textLine ?? '',
            );
        } catch (Throwable $e) {
            $artifactUid = $this->jobs->insertArtifact($jobUid, $this->artifactType(), 'default', 0, ArtifactStatus::Pending);
            $this->failArtifact($artifactUid, $jobUid, sprintf('%s generation error: %s', $this->label(), $e->getMessage()));

            return false;
        }

        // One row per result; a failed write fails only that row, like a story slide.
        $prompts = $this->promptsMetadata(system: $system, user: $prompt);
        // nr-llm's completion API does not report the model, so none is named (ADR-005).
        $aiLabel = $this->provenance($ctx, DigitalSourceType::TrainedAlgorithmicMedia)->toArray();
        $ok      = false;
        foreach ($artifacts as $artifact) {
            $artifactUid = $this->jobs->insertArtifact($jobUid, $this->artifactType(), $artifact->variant, 0, ArtifactStatus::Pending);
            try {
                $this->jobs->updateArtifact($artifactUid, [
                    'script_text' => $artifact->plainText,
                    'metadata'    => json_encode(['content' => $artifact->content, 'prompts' => $prompts, 'aiLabel' => $aiLabel], JSON_THROW_ON_ERROR),
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
     * Append the optional AI closing line (extension setting `aiLabelTexts`, ADR-005) to
     * the copy-ready plain text, after a blank line, and record it as
     * `content.closingLine` so the result view can show it next to the structured
     * content. Only `script_text` changes — never the structured answer, so the FAQ
     * JSON-LD stays exactly what schema.org defines. '' leaves the texts unchanged.
     *
     * A format with a hard length limit must fit the line into the limit in parse()
     * and override this method (SocialPostGenerator).
     *
     * @param non-empty-list<TextArtifact> $artifacts
     *
     * @return non-empty-list<TextArtifact>
     */
    protected function withClosingLine(array $artifacts, string $line): array
    {
        if ($line === '') {
            return $artifacts;
        }

        return array_map(
            static fn (TextArtifact $artifact): TextArtifact => new TextArtifact(
                $artifact->variant,
                self::appendClosingLine($artifact->plainText, $line),
                $artifact->content + ['closingLine' => $line],
            ),
            $artifacts,
        );
    }

    protected static function appendClosingLine(string $text, string $line): string
    {
        return $line === '' ? $text : $text . "\n\n" . $line;
    }

    /**
     * The system prompt carries everything this extension decides: the role, how to
     * treat the source material, the format's task, the output language and the editor's
     * audience/tone snippets (trusted configuration). Nothing derived from the source goes
     * here except a language code that SourceMaterial::language() accepted. Stored in the metadata
     * (prompts.system).
     */
    protected function systemPrompt(GenerationContext $ctx): string
    {
        $prompt = sprintf(
            '%1$s' . "\n\n" . '%2$s' . "\n\n" . 'Task: %3$s' . "\n\n"
            . 'Use only facts stated in the source material — no outside knowledge, no invented numbers. '
            . 'Write in %4$s.',
            $this->role(),
            SourceMaterial::SYSTEM_RULE,
            $this->taskInstruction($ctx),
            SourceMaterial::language($ctx->brief->language),
        );
        if ($ctx->snippets->textSections !== '') {
            $prompt .= "\n\n" . $ctx->snippets->textSections;
        }

        return $prompt . "\n\nOutput ONLY valid JSON.";
    }

    /**
     * The user prompt: the source-derived ContentBrief fields and nothing else, as one
     * SourceMaterial block (tag-like "<source…" sequences inside neutralised).
     * Stored in the metadata (prompts.user); nr-llm appends its JSON-schema instruction
     * to it before the call (and, on a repair round, the rejected answer).
     */
    protected function userPrompt(GenerationContext $ctx): string
    {
        $brief    = $ctx->brief;
        $sections = array_map(
            static fn (array $section): string => sprintf("## %s\n%s", $section['heading'], $section['body']),
            $brief->sections,
        );

        $data = sprintf(
            "Title: %s\nSummary: %s\nAudience: %s\nKey points:\n- %s\n\nSections:\n%s\n\nSource: %s",
            $brief->title,
            $brief->summary,
            $brief->audience,
            implode("\n- ", $brief->keyPoints),
            implode("\n\n", $sections),
            $ctx->document->sourceLabel,
        );

        return SourceMaterial::wrap($data);
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
