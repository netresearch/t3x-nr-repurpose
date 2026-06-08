<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Generator\Image\ImageGeneratorInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\ImageCompositorInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Psr\Log\LoggerInterface;

/**
 * Produces a branded diagram in three artifact rows for empirical comparison (spec §8):
 *   - html      : LLM-built branded HTML rendered opaque to PNG (labels 100% correct, reference)
 *   - html_bg   : DALL-E background image + the same HTML rendered transparent + compositor overlay
 *   - ki_image  : full DALL-E text-to-image from a content-derived prompt (fully AI-rendered, weakest)
 *
 * The diagram width is 1200px, auto-height. The LLM body call is budget-middleware guarded;
 * the two image-generation calls (Specialized, not middleware-guarded) are gated manually. An
 * image variant that is over budget / unavailable is marked failed; the html variant has no
 * Specialized call so it always proceeds (so a budget-starved run still yields one usable diagram).
 */
class SchaubildGenerator extends AbstractGenerator
{
    private const WIDTH = 1200;
    // gpt-image-1 landscape (DALL·E's 1792x1024 is no longer a valid size).
    private const IMAGE_SIZE = '1536x1024';
    private const IMAGE_COST = 0.05;

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
        return (bool) ($ctx->jobRow['want_schaubild'] ?? false);
    }

    public function generate(GenerationContext $ctx): bool
    {
        $jobUid = $ctx->jobUid();

        $htmlOpaque = $this->renderDiagramHtml($ctx, false);
        $htmlTransparent = $this->renderDiagramHtml($ctx, true);

        $ok = $this->generateHtmlVariant($jobUid, $htmlOpaque);
        $ok = $this->generateHtmlBgVariant($ctx, $jobUid, $htmlTransparent) || $ok;
        $ok = $this->generateKiImageVariant($ctx, $jobUid, $htmlOpaque) || $ok;

        return $ok;
    }

    /** Variant 1 — deterministic Chromium screenshot of the branded HTML. */
    private function generateHtmlVariant(int $jobUid, string $html): bool
    {
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Schaubild, 'html', 0, ArtifactStatus::Pending);
        try {
            $pngPath = $this->renderer->render($html, self::WIDTH, null, 2.0, false);
            $file = $this->fileStorage->store((string) file_get_contents($pngPath), 'schaubild-html.png');
            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $file->getUid(),
                'source_html' => $html,
                'metadata' => json_encode(['variant' => 'html', 'width' => self::WIDTH], JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->failArtifact($artifactUid, $jobUid, 'Schaubild html variant error: ' . $e->getMessage());

            return false;
        }
    }

    /** Variant 2 — DALL-E background, transparent HTML overlay composited on top. */
    private function generateHtmlBgVariant(GenerationContext $ctx, int $jobUid, string $transparentHtml): bool
    {
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Schaubild, 'html_bg', 0, ArtifactStatus::Pending);
        if (!$this->specializedAllowed($ctx, self::IMAGE_COST, $this->imageGenerator->isAvailable())) {
            $this->failArtifact($artifactUid, $jobUid, 'AI budget exhausted or image service unavailable');

            return false;
        }
        try {
            $tmpDir = $this->makeTempDir();
            $bgPath = $tmpDir . '/bg.png';
            $this->imageGenerator->generateToFile($this->backgroundPrompt($ctx), self::IMAGE_SIZE, $bgPath);

            $fgPath = $this->renderer->render($transparentHtml, self::WIDTH, null, 2.0, true);
            $outPath = $tmpDir . '/composited.png';
            $this->compositor->overlay($bgPath, $fgPath, $outPath);

            $file = $this->fileStorage->store((string) file_get_contents($outPath), 'schaubild-html-bg.png');
            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $file->getUid(),
                'source_html' => $transparentHtml,
                'metadata' => json_encode(['variant' => 'html_bg', 'bgModel' => 'dall-e-3'], JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->failArtifact($artifactUid, $jobUid, 'Schaubild html_bg variant error: ' . $e->getMessage());

            return false;
        }
    }

    /** Variant 3 — full DALL-E text-to-image from a content-derived prompt (no img2img in DALL-E). */
    private function generateKiImageVariant(GenerationContext $ctx, int $jobUid, string $referenceHtml): bool
    {
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Schaubild, 'ki_image', 0, ArtifactStatus::Pending);
        if (!$this->specializedAllowed($ctx, self::IMAGE_COST, $this->imageGenerator->isAvailable())) {
            $this->failArtifact($artifactUid, $jobUid, 'AI budget exhausted or image service unavailable');

            return false;
        }
        try {
            $tmpDir = $this->makeTempDir();
            $outPath = $tmpDir . '/ki.png';
            $this->imageGenerator->generateToFile($this->kiImagePrompt($ctx), self::IMAGE_SIZE, $outPath);

            $file = $this->fileStorage->store((string) file_get_contents($outPath), 'schaubild-ki.png');
            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $file->getUid(),
                'source_html' => $referenceHtml,
                'metadata' => json_encode(['variant' => 'ki_image', 'model' => 'dall-e-3'], JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->failArtifact($artifactUid, $jobUid, 'Schaubild ki_image variant error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Build the branded diagram HTML: ask the LLM for the diagram BODY (factual content),
     * then wrap it in the theme template (StandaloneView). Seam isolated for unit testing.
     */
    protected function renderDiagramHtml(GenerationContext $ctx, bool $transparent): string
    {
        $brief = $ctx->brief;
        $keyPoints = implode("\n- ", $brief->keyPoints);
        $prompt = sprintf(
            "Title: %s\nSummary: %s\nKey points:\n- %s\n\n"
            . 'Produce the inner HTML body of an infographic/diagram that visualises this content. '
            . 'Use semantic, self-contained HTML with inline classes only (no <html>/<head>). '
            . 'Keep every label, number and term exactly as given. Write text in language code "%s".',
            $brief->title,
            $brief->summary,
            $keyPoints,
            $brief->language,
        );
        $options = new ChatOptions(
            temperature: 0.3,
            systemPrompt: 'You are an information designer. Output an HTML fragment only.',
            beUserUid: $ctx->beUser,
            plannedCost: 0.03,
        );
        $bodyHtml = $this->completion->completeMarkdown($prompt, $options);

        return $this->renderTemplate('Schaubild', $ctx->theme, [
            'title' => $brief->title,
            'bodyHtml' => $bodyHtml,
            'transparent' => $transparent,
            'language' => $brief->language,
        ]);
    }

    private function backgroundPrompt(GenerationContext $ctx): string
    {
        return sprintf(
            'Abstract, subtle background for an infographic about "%s". Soft gradients, no text, '
            . 'leave the center calm for an overlay. Theme: %s.',
            $ctx->brief->title,
            $ctx->theme === 'nr' ? 'teal and orange corporate' : 'neutral light',
        );
    }

    private function kiImagePrompt(GenerationContext $ctx): string
    {
        $keyPoints = implode('; ', $ctx->brief->keyPoints);

        return sprintf(
            'A polished infographic/diagram illustrating "%s". Key points: %s. '
            . 'Flat modern style, clear sections and arrows.',
            $ctx->brief->title,
            $keyPoints,
        );
    }
}
