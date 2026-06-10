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
    // Default gpt-image landscape size; a layout snippet may override it via its
    // metadata {"imageSize":"WxH"} (see AbstractGenerator::resolveImageSize()).
    private const IMAGE_SIZE = '1536x1024';
    private const IMAGE_COST = 0.05;
    private const HTML_SYSTEM_PROMPT = 'You are an information designer. Output a raw HTML fragment only — '
        . 'no Markdown, no code fences.';

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

        $ctx->progress?->step('Schaubild: building HTML', 0.05);
        $htmlOpaque = $this->renderDiagramHtml($ctx, false);
        $htmlTransparent = $this->renderDiagramHtml($ctx, true);

        // The exact diagram-body LLM prompts, recorded in every variant's metadata
        // (transparency: the result view shows what was actually sent).
        $llmPrompts = ['system' => self::HTML_SYSTEM_PROMPT, 'user' => $this->diagramBodyPrompt($ctx)];
        // Layout snippets may hint a custom AI-image size; resolved once so an invalid
        // hint logs a single warning. The html variant (Chromium render) is unaffected.
        $imageSize = $this->resolveImageSize($ctx->snippets->schaubildImageSize, self::IMAGE_SIZE);

        $ctx->progress?->step('Schaubild: variant html (1/3)', 0.4);
        $ok = $this->generateHtmlVariant($jobUid, $htmlOpaque, $llmPrompts);
        $ctx->progress?->step('Schaubild: variant html_bg (2/3)', 0.6);
        $ok = $this->generateHtmlBgVariant($ctx, $jobUid, $htmlTransparent, $llmPrompts, $imageSize) || $ok;
        $ctx->progress?->step('Schaubild: variant ki_image (3/3)', 0.8);
        $ok = $this->generateKiImageVariant($ctx, $jobUid, $htmlOpaque, $imageSize) || $ok;

        return $ok;
    }

    /**
     * Variant 1 — deterministic Chromium screenshot of the branded HTML.
     *
     * @param array{system: string, user: string} $llmPrompts
     */
    private function generateHtmlVariant(int $jobUid, string $html, array $llmPrompts): bool
    {
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Schaubild, 'html', 0, ArtifactStatus::Pending);
        try {
            $pngPath = $this->renderer->render($html, self::WIDTH, null, 2.0, false);
            $file = $this->fileStorage->store((string) file_get_contents($pngPath), 'schaubild-html.png');
            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $file->getUid(),
                'source_html' => $html,
                'metadata' => json_encode([
                    'variant' => 'html',
                    'width' => self::WIDTH,
                    'prompts' => $this->promptsMetadata(system: $llmPrompts['system'], user: $llmPrompts['user']),
                ], JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->failArtifact($artifactUid, $jobUid, 'Schaubild html variant error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Variant 2 — AI background image, transparent HTML overlay composited on top.
     *
     * @param array{system: string, user: string} $llmPrompts
     */
    private function generateHtmlBgVariant(GenerationContext $ctx, int $jobUid, string $transparentHtml, array $llmPrompts, string $imageSize): bool
    {
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Schaubild, 'html_bg', 0, ArtifactStatus::Pending);
        if (!$this->specializedAllowed($ctx, self::IMAGE_COST, $this->imageGenerator->isAvailable())) {
            $this->failArtifact($artifactUid, $jobUid, 'AI budget exhausted or image service unavailable');

            return false;
        }
        try {
            $tmpDir = $this->makeTempDir();
            $bgPath = $tmpDir . '/bg.png';
            $bgPrompt = $this->backgroundPrompt($ctx);
            $ctx->progress?->step('Schaubild: generating background image', 0.65);
            $this->imageGenerator->generateToFile($bgPrompt, $imageSize, $bgPath);

            $fgPath = $this->renderer->render($transparentHtml, self::WIDTH, null, 2.0, true);
            $outPath = $tmpDir . '/composited.png';
            $this->compositor->overlay($bgPath, $fgPath, $outPath);

            $file = $this->fileStorage->store((string) file_get_contents($outPath), 'schaubild-html-bg.png');
            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $file->getUid(),
                'source_html' => $transparentHtml,
                'metadata' => json_encode([
                    'variant' => 'html_bg',
                    'bgModel' => $this->imageGenerator->getModel(),
                    'prompts' => $this->promptsMetadata(
                        system: $llmPrompts['system'],
                        user: $llmPrompts['user'],
                        image: $bgPrompt,
                        imageModel: $this->imageGenerator->getModel(),
                        imageSize: $imageSize,
                    ),
                ], JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->failArtifact($artifactUid, $jobUid, 'Schaubild html_bg variant error: ' . $e->getMessage());

            return false;
        }
    }

    /** Variant 3 — full AI text-to-image from a content-derived prompt (no img2img available). */
    private function generateKiImageVariant(GenerationContext $ctx, int $jobUid, string $referenceHtml, string $imageSize): bool
    {
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Schaubild, 'ki_image', 0, ArtifactStatus::Pending);
        if (!$this->specializedAllowed($ctx, self::IMAGE_COST, $this->imageGenerator->isAvailable())) {
            $this->failArtifact($artifactUid, $jobUid, 'AI budget exhausted or image service unavailable');

            return false;
        }
        try {
            $tmpDir = $this->makeTempDir();
            $outPath = $tmpDir . '/ki.png';
            $kiPrompt = $this->kiImagePrompt($ctx);
            $this->imageGenerator->generateToFile($kiPrompt, $imageSize, $outPath);

            $file = $this->fileStorage->store((string) file_get_contents($outPath), 'schaubild-ki.png');
            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $file->getUid(),
                'source_html' => $referenceHtml,
                'metadata' => json_encode([
                    'variant' => 'ki_image',
                    'model' => $this->imageGenerator->getModel(),
                    'prompts' => $this->promptsMetadata(
                        image: $kiPrompt,
                        imageModel: $this->imageGenerator->getModel(),
                        imageSize: $imageSize,
                    ),
                ], JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->failArtifact($artifactUid, $jobUid, 'Schaubild ki_image variant error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * The exact user prompt of the diagram-body LLM call — also recorded verbatim in the
     * artifact metadata (prompts.user), so build it in one place only.
     */
    private function diagramBodyPrompt(GenerationContext $ctx): string
    {
        $brief = $ctx->brief;
        $keyPoints = implode("\n- ", $brief->keyPoints);
        $prompt = sprintf(
            "Title: %s\nSummary: %s\nKey points:\n- %s\n\n"
            . 'Produce the inner HTML body of an INFOGRAPHIC that visualises this content — not a '
            . 'text document. Lay it out as distinct visual blocks (cards / columns / a simple flow), '
            . 'each with a short heading and the key figure or term made prominent; use inline CSS '
            . 'styles for layout, spacing, colour accents and typographic hierarchy. Avoid long '
            . 'paragraphs and plain bullet lists. Self-contained HTML only (no <html>/<head>, no '
            . 'external assets). Keep every label, number and term exactly as given. '
            . 'Write text in language code "%s".',
            $brief->title,
            $brief->summary,
            $keyPoints,
            $brief->language,
        );
        if ($ctx->snippets->schaubildSections !== '') {
            $prompt .= "\n\n" . $ctx->snippets->schaubildSections;
        }

        return $prompt;
    }

    /**
     * Build the branded diagram HTML: ask the LLM for the diagram BODY (factual content),
     * then wrap it in the theme template (StandaloneView). Seam isolated for unit testing.
     */
    protected function renderDiagramHtml(GenerationContext $ctx, bool $transparent): string
    {
        $brief = $ctx->brief;
        $options = new ChatOptions(
            temperature: 0.3,
            systemPrompt: self::HTML_SYSTEM_PROMPT,
            beUserUid: $ctx->beUser,
            plannedCost: 0.03,
        );
        $bodyHtml = self::stripCodeFences($this->completion->completeMarkdown($this->diagramBodyPrompt($ctx), $options));

        return $this->renderTemplate('Schaubild', $ctx->theme, [
            'title' => $brief->title,
            'bodyHtml' => $bodyHtml,
            'transparent' => $transparent,
            'language' => $brief->language,
        ]);
    }

    /**
     * Strip a single Markdown code fence the LLM sometimes wraps the HTML fragment in
     * (```html … ```), which would otherwise render as literal text. Plain string ops
     * (no regex) keep this simple and backtracking-safe.
     */
    protected static function stripCodeFences(string $html): string
    {
        $trimmed = trim($html);
        if (!str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        // Drop the opening fence (``` plus an optional language tag): up to the first
        // newline for a multi-line fence, or — for a single-line fence like
        // "```html<p>…</p>```" — up to the first '<' so the HTML fragment is preserved.
        $newlinePos = strpos($trimmed, "\n");
        if ($newlinePos !== false) {
            $trimmed = substr($trimmed, $newlinePos + 1);
        } else {
            $tagPos = strpos($trimmed, '<');
            $trimmed = $tagPos !== false ? substr($trimmed, $tagPos) : '';
        }

        // Drop a trailing closing fence.
        $trimmed = rtrim($trimmed);
        if (str_ends_with($trimmed, '```')) {
            $trimmed = substr($trimmed, 0, -3);
        }

        return trim($trimmed);
    }

    private function backgroundPrompt(GenerationContext $ctx): string
    {
        return sprintf(
            'Abstract, subtle background for an infographic about "%s". Soft gradients, no text, '
            . 'leave the center calm for an overlay. Theme: %s.',
            $ctx->brief->title,
            $ctx->theme === 'nr' ? 'teal and orange corporate' : 'neutral light',
        ) . $this->imageHints($ctx);
    }

    private function kiImagePrompt(GenerationContext $ctx): string
    {
        $keyPoints = implode('; ', $ctx->brief->keyPoints);

        return sprintf(
            'A polished infographic/diagram illustrating "%s". Key points: %s. '
            . 'Flat modern style, clear sections and arrows.',
            $ctx->brief->title,
            $keyPoints,
        ) . $this->imageHints($ctx);
    }

    /**
     * Style/audience guidance for the image prompts, woven in from the job's resolved
     * prompt snippets. Empty without snippets, keeping the prompts byte-identical.
     */
    private function imageHints(GenerationContext $ctx): string
    {
        $hints = '';
        if ($ctx->snippets->styleHint !== '') {
            $hints .= ' Visual style: ' . $ctx->snippets->styleHint;
        }
        if ($ctx->snippets->audienceHint !== '') {
            $hints .= ' Target audience: ' . $ctx->snippets->audienceHint;
        }

        return $hints;
    }
}
