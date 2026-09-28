<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Generator\Support\InvalidLlmOutputException;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Provenance\AiProvenance;
use Netresearch\NrRepurpose\Provenance\DigitalSourceType;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared base for the real artifact generators. Provides Specialized-call guarding
 * (budget + availability), a per-run temp directory and a uniform failed-artifact helper.
 * Generators that render the branded theme templates use RendersThemeTemplates.
 *
 * Concrete generators MUST NOT throw for a single-artifact business failure: record the
 * artifact as failed and return false so sibling generators keep running.
 */
abstract class AbstractGenerator implements ArtifactGeneratorInterface
{
    /** Artifact error when the job owner's groups lack `nrrepurpose:generate_audio`. */
    protected const DENIED_AUDIO = 'Not permitted: the job owner\'s backend groups do not grant "Generate podcast audio" (nrrepurpose:generate_audio)';

    /** Artifact error when the job owner's groups lack `nrrepurpose:generate_vision`. */
    protected const DENIED_VISION = 'Not permitted: the job owner\'s backend groups do not grant "Generate AI imagery" (nrrepurpose:generate_vision)';

    private const TEMP_DIR_PREFIX = 'nrrepurpose_';

    /** @var array<string, true> directories made by makeTempDir() and not removed yet */
    private array $tempDirs = [];

    public function __construct(
        protected readonly JobProcessingRepository $jobs,
        protected readonly BudgetServiceInterface $budget,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * Guard a Specialized nr-llm call (TTS/FAL) which is NOT covered by the budget middleware.
     * Returns true when the planned cost is within budget AND the service is available.
     */
    protected function specializedAllowed(GenerationContext $ctx, float $plannedCost, bool $serviceAvailable): bool
    {
        if (!$this->budget->check($ctx->beUser, $plannedCost)->allowed) {
            return false;
        }

        return $serviceAvailable;
    }

    /**
     * Absolute path to a fresh, writable per-run temp directory (auto-created). It is
     * removed by removeTempDir(), or at the latest by removeTempDirs(), which the
     * orchestrator calls after each generator; the worker runs long.
     */
    protected function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/' . self::TEMP_DIR_PREFIX . bin2hex(random_bytes(8));
        GeneralUtility::mkdir_deep($dir);
        $this->tempDirs[$dir] = true;

        return $dir;
    }

    /** Remove one directory made by makeTempDir(), with its content; anything else is left alone. */
    protected function removeTempDir(?string $dir): void
    {
        if ($dir === null || !isset($this->tempDirs[$dir])) {
            return;
        }

        unset($this->tempDirs[$dir]);
        if (is_dir($dir)) {
            // $dir is a path makeTempDir() generated, never user input.
            GeneralUtility::rmdir($dir, true);
        }
    }

    /** Remove every directory made by makeTempDir() that is still there. */
    public function removeTempDirs(): void
    {
        foreach (array_keys($this->tempDirs) as $dir) {
            $this->removeTempDir($dir);
        }
    }

    /** Delete a file a renderer produced for this generator once it has been stored. */
    protected function discardRenderedFile(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            // $path is a renderer's own output (random name in its output dir), never user input.
            unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }
    }

    /**
     * Canonical shape of the "prompts" object every generator stores in the artifact
     * metadata JSON for full generation transparency. Texts are verbatim and complete —
     * never truncated. All keys are optional; an artifact only carries the calls it
     * actually made:
     *
     *   system     LLM system prompt
     *   user       LLM user prompt
     *   image      image-generation prompt
     *   imageModel image model id (ImageGeneratorInterface::getModel())
     *   imageSize  effective image size used ("WIDTHxHEIGHT")
     *   ttsModel   TTS model id (SpeechSynthesizerInterface::getModel())
     *   voices     per-speaker TTS voice map {speaker: voice}
     *
     * The Show view renders this object in the per-artifact "Generation parameters" panel.
     *
     * @param array<string, string>|null $voices
     *
     * @return array<string, mixed>
     */
    protected function promptsMetadata(
        ?string $system = null,
        ?string $user = null,
        ?string $image = null,
        ?string $imageModel = null,
        ?string $imageSize = null,
        ?string $ttsModel = null,
        ?array $voices = null,
    ): array {
        return array_filter(
            [
                'system'     => $system,
                'user'       => $user,
                'image'      => $image,
                'imageModel' => $imageModel,
                'imageSize'  => $imageSize,
                'ttsModel'   => $ttsModel,
                'voices'     => $voices,
            ],
            static fn (string|array|null $value): bool => $value !== null,
        );
    }

    /**
     * The AI-origin statement of an artifact (ADR-005). Its toArray() is stored as the
     * `aiLabel` block of every done artifact's metadata; passed to JobFileStorage::store()
     * it also marks the stored file itself. $models names only models that are known.
     *
     * @param array<string, string> $models role => model id
     */
    protected function provenance(GenerationContext $ctx, DigitalSourceType $sourceType, array $models = []): AiProvenance
    {
        return new AiProvenance($ctx->aiLabel->generator, $sourceType, $models);
    }

    /**
     * Resolve the effective AI-image size: a layout prompt snippet may hint a custom size
     * via its metadata {"imageSize":"WxH"}. The hint is used only when it satisfies the
     * FULL gpt-image-* contract that nr-llm's ImageGenerationOptions enforces (both
     * dimensions divisible by 16, at most 3840x2160, aspect ratio between 1:3 and 3:1) —
     * being exactly as strict here guarantees an accepted hint can never make the
     * downstream options validation throw and fail the artifact. Anything else falls
     * back to the generator's default and logs a warning. The Chromium HTML renders
     * are unaffected; only AI-image calls are.
     */
    protected function resolveImageSize(string $hint, string $default): string
    {
        if ($hint === '') {
            return $default;
        }

        if (preg_match('/^(\d{2,4})x(\d{2,4})$/', $hint, $matches) === 1) {
            $width  = (int) $matches[1];
            $height = (int) $matches[2];

            // Mirrors ImageGenerationOptions::validateGptImageSize(): divisible by 16,
            // max 3840x2160, aspect within [1:3, 3:1] via integer math (W<=3H, H<=3W).
            if ($width % 16 === 0 && $height % 16 === 0
                && $width >= 16 && $height >= 16
                && $width <= 3840 && $height <= 2160
                && $width <= 3 * $height && $height <= 3 * $width
            ) {
                return $hint;
            }
        }

        $this->logger->warning('Ignoring invalid imageSize hint from layout snippet', [
            'hint'     => $hint,
            'fallback' => $default,
        ]);

        return $default;
    }

    /** Record a previously-inserted artifact row as failed and log the reason. */
    protected function failArtifact(int $artifactUid, int $jobUid, string $reason): void
    {
        $this->logger->warning('Artifact generation failed', [
            'job'      => $jobUid,
            'artifact' => $artifactUid,
            'reason'   => $reason,
        ]);
        $this->jobs->updateArtifact($artifactUid, [
            'status'        => ArtifactStatus::Failed->value,
            'error_message' => $reason,
        ]);
    }

    /**
     * Record a failed step caused by $e. The row's error_message is shown to every module
     * user, so only this extension's own messages reach it — the LLM-output checks
     * (InvalidLlmOutputException) and the rendering primitives (RenderingException), whose
     * texts are written for that. Any other exception (the text model, FAL, the database)
     * can carry provider detail, paths or SQL: the row gets "<step> failed" and the
     * exception goes to the server log.
     */
    protected function failArtifactFrom(int $artifactUid, int $jobUid, string $step, Throwable $e): void
    {
        if ($e instanceof InvalidLlmOutputException || $e instanceof RenderingException) {
            $this->failArtifact($artifactUid, $jobUid, $step . ' error: ' . $e->getMessage());

            return;
        }

        $this->logger->error($step . ' failed', ['job' => $jobUid, 'artifact' => $artifactUid, 'exception' => $e]);
        $this->failArtifact($artifactUid, $jobUid, $step . ' failed');
    }
}
