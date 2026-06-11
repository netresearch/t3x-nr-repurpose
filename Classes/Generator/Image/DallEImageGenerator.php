<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Image;

use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use Netresearch\NrRepurpose\Rendering\RenderingException;

/**
 * Default ImageGeneratorInterface: delegates to nr-llm's DallEImageService (OpenAI images).
 * The effective model comes from nr-llm's Model registry (the active image model,
 * preferring the default record) and falls back to gpt-image-2 (OpenAI, June 2026) when
 * the registry has none, so editors switch models in the nr-llm backend module without
 * code changes. gpt-image-* models accept arbitrary WIDTHxHEIGHT sizes as long as both
 * dimensions are divisible by 16, the aspect ratio stays between 1:3 and 3:1 and the
 * size does not exceed 3840x2160 (validated by nr-llm's ImageGenerationOptions for
 * gpt-image-* models). There is no image-to-image, so every variant uses text-to-image
 * from a content-derived prompt. gpt-image-* returns base64, which the result's
 * saveToFile() decodes directly.
 */
final class DallEImageGenerator implements ImageGeneratorInterface
{
    /** Documented fallback when nr-llm's Model registry has no active image model. */
    public const MODEL = 'gpt-image-2';

    /** Effective model, resolved lazily once per instance by getModel(). */
    private ?string $model = null;

    public function __construct(private readonly DallEImageService $dalle) {}

    public function isAvailable(): bool
    {
        return $this->dalle->isAvailable();
    }

    /**
     * The effective model: the active image model from nr-llm's Model registry, falling
     * back to self::MODEL. generateToFile() uses the same resolved value, so the artifact
     * metadata recorded from this method always names the model that actually ran.
     */
    public function getModel(): string
    {
        // The method_exists() guard keeps the extension installable against an nr-llm
        // dev-main without resolveDefaultModel(); drop it once nr-llm's specialized
        // model registry change is merged and required here.
        $this->model ??= method_exists($this->dalle, 'resolveDefaultModel')
            ? $this->dalle->resolveDefaultModel(self::MODEL)
            : self::MODEL;

        return $this->model;
    }

    public function generateToFile(string $prompt, string $size, string $outputPath): void
    {
        try {
            $result = $this->dalle->generate($prompt, new ImageGenerationOptions(model: $this->getModel(), size: $size));
            if (!$result->saveToFile($outputPath)) {
                throw RenderingException::because('DALL-E could not save generated image to ' . $outputPath, 1749411000);
            }
        } catch (RenderingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw RenderingException::because('DALL-E image generation failed: ' . $e->getMessage(), 1749411001, $e);
        }
    }
}
