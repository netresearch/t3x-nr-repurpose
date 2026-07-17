<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Image;

use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use Netresearch\NrRepurpose\Rendering\RenderingException;

/**
 * Default ImageGeneratorInterface: delegates to nr-llm's DallEImageService (OpenAI images).
 * The effective model resolves through nr-llm's three-tier system: the "nr_repurpose_image"
 * Configuration record decides (editors swap the model and maintain a system-prompt style
 * preamble there, without touching any consumer), falling back to the registry's active
 * image model and finally to gpt-image-2 (OpenAI, June 2026). gpt-image-* models accept
 * arbitrary WIDTHxHEIGHT sizes as long as both dimensions are divisible by 16, the aspect
 * ratio stays between 1:3 and 3:1 and the size does not exceed 3840x2160 (validated by
 * nr-llm's ImageGenerationOptions for gpt-image-* models). There is no image-to-image, so
 * every variant uses text-to-image from a content-derived prompt. gpt-image-* returns
 * base64, which the result's saveToFile() decodes directly.
 */
final class DallEImageGenerator implements ImageGeneratorInterface
{
    /** The nr-llm Configuration record (identifier) steering image generation. */
    public const CONFIGURATION = 'nr_repurpose_image';

    /** Documented fallback when neither the Configuration nor the Model registry resolves. */
    public const MODEL = 'gpt-image-2';

    /** Effective model, resolved lazily once per instance by getModel(). */
    private ?string $model = null;

    /** Configuration system prompt (image style preamble), resolved lazily with getPromptPreamble(). */
    private ?string $promptPreamble = null;

    public function __construct(private readonly DallEImageService $dalle) {}

    public function isAvailable(): bool
    {
        return $this->dalle->isAvailable();
    }

    /**
     * The effective model: the "nr_repurpose_image" Configuration's model, else the active
     * image model from nr-llm's Model registry, else self::MODEL. generateToFile() uses the
     * same resolved value, so the artifact metadata recorded from this method always names
     * the model that actually ran.
     */
    public function getModel(): string
    {
        $this->model ??= $this->dalle->resolveModelForConfiguration(self::CONFIGURATION, self::MODEL);

        return $this->model;
    }

    /**
     * Style preamble for every image prompt, maintained as the system prompt of the
     * "nr_repurpose_image" Configuration record. '' when the record has no system prompt.
     * Generators prepend it to their image prompts BEFORE recording the prompt in the
     * artifact metadata, so the recorded prompt stays the exact text that was sent.
     */
    public function getPromptPreamble(): string
    {
        $this->promptPreamble ??= $this->dalle->getConfigurationSystemPrompt(self::CONFIGURATION);

        return $this->promptPreamble;
    }

    public function generateToFile(string $prompt, string $size, string $outputPath): void
    {
        try {
            $result = $this->dalle->generate($prompt, $this->buildOptions($size));
            if (!$result->saveToFile($outputPath)) {
                throw RenderingException::because('DALL-E could not save generated image to ' . $outputPath, 1749411000);
            }
        } catch (RenderingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw RenderingException::because('DALL-E image generation failed: ' . $e->getMessage(), 1749411001, $e);
        }
    }

    /**
     * The 'configuration' option is pure usage-attribution metadata in nr-llm
     * (per-configuration cost breakdowns), naming the record this call is billed to.
     */
    private function buildOptions(string $size): ImageGenerationOptions
    {
        return new ImageGenerationOptions(
            model: $this->getModel(),
            size: $size,
            configuration: self::CONFIGURATION,
        );
    }
}
