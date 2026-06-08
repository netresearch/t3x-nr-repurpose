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
 * Produces one 9:16 Instagram story (1080x1920 PNG, spec §10). The LLM condenses the brief
 * into a headline + subline (budget-middleware guarded CompletionService); the branded 9:16
 * template is rendered to PNG. Optionally (when the image service is available and within
 * budget) a KI background is generated and the transparent text layer composited over it.
 * The KI background is best-effort: over budget / unavailable falls back to the flat-render PNG.
 */
class StoryGenerator extends AbstractGenerator
{
    private const WIDTH = 1080;
    private const HEIGHT = 1920;
    private const IMAGE_SIZE = '1024x1792';
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
        return (bool) ($ctx->jobRow['want_story'] ?? false);
    }

    public function generate(GenerationContext $ctx): bool
    {
        $jobUid = $ctx->jobUid();
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Story, 'default', 0, ArtifactStatus::Pending);

        try {
            $useKiBackground = $this->specializedAllowed($ctx, self::IMAGE_COST, $this->imageGenerator->isAvailable());

            if ($useKiBackground) {
                $html = $this->renderStoryHtml($ctx, true);
                $pngPath = $this->composeWithKiBackground($ctx, $html);
                $metadata = ['width' => self::WIDTH, 'height' => self::HEIGHT, 'background' => 'ki'];
            } else {
                $html = $this->renderStoryHtml($ctx, false);
                $pngPath = $this->renderer->render($html, self::WIDTH, self::HEIGHT, 1.0, false);
                $metadata = ['width' => self::WIDTH, 'height' => self::HEIGHT, 'background' => 'flat'];
            }

            $file = $this->fileStorage->store((string) file_get_contents($pngPath), 'story.png');
            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $file->getUid(),
                'source_html' => $html,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->failArtifact($artifactUid, $jobUid, 'Story generation error: ' . $e->getMessage());

            return false;
        }
    }

    private function composeWithKiBackground(GenerationContext $ctx, string $transparentHtml): string
    {
        $tmpDir = $this->makeTempDir();
        $bgPath = $tmpDir . '/bg.png';
        $this->imageGenerator->generateToFile($this->backgroundPrompt($ctx), self::IMAGE_SIZE, $bgPath);

        $fgPath = $this->renderer->render($transparentHtml, self::WIDTH, self::HEIGHT, 1.0, true);
        $outPath = $tmpDir . '/story.png';
        $this->compositor->overlay($bgPath, $fgPath, $outPath);

        return $outPath;
    }

    /** Build the branded 9:16 HTML; seam isolated for unit testing. */
    protected function renderStoryHtml(GenerationContext $ctx, bool $transparent): string
    {
        $brief = $ctx->brief;
        $prompt = sprintf(
            "Title: %s\nSummary: %s\n\nCondense this into one punchy Instagram-story headline (<=60 chars) "
            . 'and a short subline (<=110 chars). Write in language code "%s". '
            . 'Output ONLY JSON {"headline":"...","subline":"..."}.',
            $brief->title,
            $brief->summary,
            $brief->language,
        );
        $options = new ChatOptions(
            temperature: 0.5,
            responseFormat: 'json',
            systemPrompt: 'You are a social-media copywriter. Output ONLY valid JSON.',
            beUserUid: $ctx->beUser,
            plannedCost: 0.01,
        );
        $data = $this->completion->completeJson($prompt, $options);

        return $this->renderTemplate('Story', $ctx->theme, [
            'headline' => (string) ($data['headline'] ?? $brief->title),
            'subline' => (string) ($data['subline'] ?? $brief->summary),
            'transparent' => $transparent,
        ]);
    }

    private function backgroundPrompt(GenerationContext $ctx): string
    {
        return sprintf(
            'Vertical 9:16 abstract background for an Instagram story about "%s". No text, soft '
            . 'gradients, leave space top and bottom for overlaid copy. Theme: %s.',
            $ctx->brief->title,
            $ctx->theme === 'nr' ? 'teal and orange corporate' : 'neutral light',
        );
    }
}
