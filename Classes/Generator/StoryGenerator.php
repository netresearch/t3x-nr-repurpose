<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Generator\Image\ImageGeneratorInterface;
use Netresearch\NrRepurpose\Generator\Support\StorySlide;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\ImageCompositorInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Psr\Log\LoggerInterface;

/**
 * Produces a multi-slide 9:16 Instagram-story carousel (1080x1920 PNG per slide, spec §10).
 * ONE LLM call (budget-middleware guarded CompletionService; planned cost scales with the
 * expected slide count) writes the copy for every slide: a cover (hook/title), one slide per
 * key point (at most four) and an outro (takeaway + source attribution) — capped at six
 * slides total. Each slide is rendered from the branded 9:16 template into its own artifact
 * row (variant "slide-1" … "slide-N", slide role/index/total in the metadata); a failed
 * slide render fails only that slide's artifact, not its siblings.
 *
 * Optionally (when the image service is available and within budget) ONE shared KI
 * background is generated and composited behind every slide — visual coherence and a single
 * image cost. The KI background is best-effort: over budget / unavailable / a generation
 * error falls back to flat renders.
 */
class StoryGenerator extends AbstractGenerator
{
    private const WIDTH = 1080;
    private const HEIGHT = 1920;
    // Default gpt-image portrait size; the 2:3 image is composited as a background behind
    // the 9:16 story canvas, so the aspect difference is fine. A layout snippet may
    // override it via its metadata {"imageSize":"WxH"} (AbstractGenerator::resolveImageSize()).
    private const IMAGE_SIZE = '1024x1536';
    private const IMAGE_COST = 0.05;
    private const COPY_COST_PER_SLIDE = 0.01;
    private const MAX_POINT_SLIDES = 4;
    private const MAX_SLIDES = 6;
    // Copy limits the prompt asks the LLM for; the parser enforces the same limits so
    // non-compliant copy cannot overflow the fixed 9:16 slide layout.
    private const MAX_HEADLINE_CHARS = 60;
    private const MAX_SUBLINE_CHARS = 110;
    private const COPY_SYSTEM_PROMPT = 'You are a social-media copywriter. Output ONLY valid JSON.';

    public function __construct(
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
        LoggerInterface $logger,
        private readonly CompletionServiceInterface $completion,
        private readonly HtmlToImageRendererInterface $renderer,
        private readonly ImageCompositorInterface $compositor,
        private readonly ImageGeneratorInterface $imageGenerator,
        private readonly JobFileStorage $fileStorage,
    ) {
        parent::__construct($jobs, $budget, $logger);
    }

    public function supports(GenerationContext $ctx): bool
    {
        return (bool) ($ctx->jobRow['want_story'] ?? false);
    }

    public function generate(GenerationContext $ctx): bool
    {
        $jobUid = $ctx->jobUid();

        try {
            $ctx->progress?->step('Story: writing copy', 0.05);
            $slides = $this->buildSlides($ctx);
        } catch (\Throwable $e) {
            $this->failStoryUpfront($jobUid, 'Story generation error: ' . $e->getMessage());

            return false;
        }

        if ($slides === []) {
            $this->failStoryUpfront($jobUid, 'Story generation error: the LLM returned no usable slides');

            return false;
        }

        // Layout snippets may hint a custom AI-image size; resolved once so an invalid
        // hint logs a single warning. The slide renders (Chromium) are unaffected.
        $imageSize = $this->resolveImageSize($ctx->snippets->storyImageSize, self::IMAGE_SIZE);
        $ctx->progress?->step('Story: background image', 0.3);
        $backgroundPath = $this->generateSharedBackground($ctx, $imageSize);

        $total = count($slides);
        $ok = false;
        foreach ($slides as $i => $slide) {
            $ctx->progress?->step(sprintf('Story: slide %d/%d', $i + 1, $total), 0.4 + 0.6 * $i / $total);
            $slideOk = $this->generateSlideArtifact($ctx, $jobUid, $slide, $i + 1, $total, $backgroundPath, $imageSize);
            $ok = $slideOk || $ok;
        }

        return $ok;
    }

    /**
     * The exact user prompt of the carousel-copy LLM call — also recorded verbatim in each
     * slide's artifact metadata (prompts.user), so build it in one place only.
     */
    private function carouselPrompt(GenerationContext $ctx): string
    {
        $brief = $ctx->brief;
        $keyPoints = array_slice($brief->keyPoints, 0, self::MAX_POINT_SLIDES);
        $prompt = sprintf(
            "Title: %s\nSummary: %s\nKey points:\n- %s\nSource: %s\n\n"
            . 'Write an Instagram story carousel for this content: first a "cover" slide with a punchy '
            . 'hook/title, then one "point" slide per key point, and finally an "outro" slide with the '
            . 'main takeaway and the source attribution. Headline <=%d chars and subline <=%d chars '
            . 'per slide. Write in language code "%s". Output ONLY JSON '
            . '{"slides":[{"role":"cover|point|outro","headline":"...","subline":"..."}]}.',
            $brief->title,
            $brief->summary,
            implode("\n- ", $keyPoints),
            $ctx->document->sourceLabel,
            self::MAX_HEADLINE_CHARS,
            self::MAX_SUBLINE_CHARS,
            $brief->language,
        );
        if ($ctx->snippets->storySections !== '') {
            $prompt .= "\n\n" . $ctx->snippets->storySections;
        }

        return $prompt;
    }

    /**
     * Ask the LLM once for the whole carousel and parse it into usable slides.
     *
     * @return list<StorySlide>
     */
    private function buildSlides(GenerationContext $ctx): array
    {
        $keyPoints = array_slice($ctx->brief->keyPoints, 0, self::MAX_POINT_SLIDES);
        $options = new ChatOptions(
            temperature: 0.5,
            responseFormat: 'json',
            systemPrompt: self::COPY_SYSTEM_PROMPT,
            beUserUid: $ctx->beUser,
            // cover + one slide per key point + outro; capped at MAX_SLIDES by the point cap.
            plannedCost: self::COPY_COST_PER_SLIDE * (count($keyPoints) + 2),
        );

        return $this->parseSlides($this->completion->completeJson($this->carouselPrompt($ctx), $options));
    }

    /**
     * Tolerant parse of the LLM carousel JSON: entries without a headline (or that are not
     * objects) are skipped, unknown roles degrade to "point", and the total is capped at
     * MAX_SLIDES.
     *
     * @param array<mixed> $data
     *
     * @return list<StorySlide>
     */
    private function parseSlides(array $data): array
    {
        $rawSlides = is_array($data['slides'] ?? null) ? $data['slides'] : [];

        $slides = [];
        foreach ($rawSlides as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $headline = is_scalar($raw['headline'] ?? null) ? trim((string) $raw['headline']) : '';
            if ($headline === '') {
                continue;
            }
            $subline = is_scalar($raw['subline'] ?? null) ? trim((string) $raw['subline']) : '';
            $role = is_scalar($raw['role'] ?? null) ? (string) $raw['role'] : '';
            if (!in_array($role, [StorySlide::ROLE_COVER, StorySlide::ROLE_POINT, StorySlide::ROLE_OUTRO], true)) {
                $role = StorySlide::ROLE_POINT;
            }
            $slides[] = new StorySlide(
                $role,
                mb_substr($headline, 0, self::MAX_HEADLINE_CHARS),
                mb_substr($subline, 0, self::MAX_SUBLINE_CHARS),
            );
            if (count($slides) >= self::MAX_SLIDES) {
                break;
            }
        }

        return $slides;
    }

    /**
     * Generate the single KI background shared by all slides (visual coherence, one image
     * cost). Best-effort: over budget, service unavailable or a generation error all fall
     * back to flat renders (null).
     */
    private function generateSharedBackground(GenerationContext $ctx, string $imageSize): ?string
    {
        if (!$this->specializedAllowed($ctx, self::IMAGE_COST, $this->imageGenerator->isAvailable())) {
            return null;
        }

        try {
            $backgroundPath = $this->makeTempDir() . '/bg.png';
            $this->imageGenerator->generateToFile($this->backgroundPrompt($ctx), $imageSize, $backgroundPath);

            return $backgroundPath;
        } catch (\Throwable $e) {
            $this->logger->warning('Story background generation failed, falling back to flat slides', [
                'job' => $ctx->jobUid(),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Render one slide into its own artifact row; a failure fails only this slide. */
    private function generateSlideArtifact(
        GenerationContext $ctx,
        int $jobUid,
        StorySlide $slide,
        int $index,
        int $total,
        ?string $backgroundPath,
        string $imageSize,
    ): bool {
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Story, 'slide-' . $index, 0, ArtifactStatus::Pending);
        $hasBackground = $backgroundPath !== null;
        $metadata = [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'background' => $hasBackground ? 'ki' : 'flat',
            'role' => $slide->role,
            'slideIndex' => $index,
            'slideTotal' => $total,
            // Every slide carries the full copy prompts; the shared background image
            // prompt/model/size only when a KI background was actually composited.
            'prompts' => $this->promptsMetadata(
                system: self::COPY_SYSTEM_PROMPT,
                user: $this->carouselPrompt($ctx),
                image: $hasBackground ? $this->backgroundPrompt($ctx) : null,
                imageModel: $hasBackground ? $this->imageGenerator->getModel() : null,
                imageSize: $hasBackground ? $imageSize : null,
            ),
        ];

        try {
            $html = $this->renderSlideHtml($ctx, $slide, $index, $total, $backgroundPath !== null);

            if ($backgroundPath !== null) {
                $fgPath = $this->renderer->render($html, self::WIDTH, self::HEIGHT, 1.0, true);
                $pngPath = $this->makeTempDir() . '/slide.png';
                $this->compositor->overlay($backgroundPath, $fgPath, $pngPath);
            } else {
                $pngPath = $this->renderer->render($html, self::WIDTH, self::HEIGHT, 1.0, false);
            }

            $file = $this->fileStorage->store((string) file_get_contents($pngPath), sprintf('story-slide-%d.png', $index));
            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $file->getUid(),
                'source_html' => $html,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Keep the slide identity on the failed row so the result view can still place it.
            // JSON_THROW_ON_ERROR matches the success path; $metadata is a fixed shape of
            // local scalars, so encoding cannot actually fail here.
            $this->jobs->updateArtifact($artifactUid, ['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
            $this->failArtifact($artifactUid, $jobUid, sprintf('Story slide %d/%d error: %s', $index, $total, $e->getMessage()));

            return false;
        }
    }

    /**
     * No usable slides (LLM error or empty/garbage carousel): record one failed story row
     * so the result view surfaces the failure.
     */
    private function failStoryUpfront(int $jobUid, string $reason): void
    {
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Story, 'default', 0, ArtifactStatus::Pending);
        $this->failArtifact($artifactUid, $jobUid, $reason);
    }

    /** Build one branded 9:16 slide HTML; seam isolated for unit testing. */
    protected function renderSlideHtml(GenerationContext $ctx, StorySlide $slide, int $index, int $total, bool $transparent): string
    {
        return $this->renderTemplate('Story', $ctx->theme, [
            'headline' => $slide->headline,
            'subline' => $slide->subline,
            'role' => $slide->role,
            'slideIndex' => $index,
            'slideTotal' => $total,
            'sourceLabel' => $ctx->document->sourceLabel,
            'transparent' => $transparent,
        ]);
    }

    private function backgroundPrompt(GenerationContext $ctx): string
    {
        $prompt = sprintf(
            'Vertical 9:16 abstract background for an Instagram story about "%s". No text, soft '
            . 'gradients, leave space top and bottom for overlaid copy. Theme: %s.',
            $ctx->brief->title,
            $ctx->theme === 'nr' ? 'teal and orange corporate' : 'neutral light',
        );

        // Prepend the editor-maintained style preamble (the steering nr-llm Configuration's
        // system prompt) so it is part of the exact prompt sent AND recorded in the metadata.
        $preamble = $this->imageGenerator->getPromptPreamble();

        return $preamble !== '' ? $preamble . "\n\n" . $prompt : $prompt;
    }
}
