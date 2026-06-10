<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Image;

use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use Netresearch\NrRepurpose\Rendering\RenderingException;

/**
 * Default ImageGeneratorInterface: delegates to nr-llm's DallEImageService (OpenAI images).
 * Uses the gpt-image-2 model (OpenAI, June 2026), which accepts arbitrary WIDTHxHEIGHT
 * sizes as long as both dimensions are divisible by 16, the aspect ratio stays between
 * 1:3 and 3:1 and the size does not exceed 3840x2160 (validated by nr-llm's
 * ImageGenerationOptions for gpt-image-* models). There is no image-to-image, so every
 * variant uses text-to-image from a content-derived prompt. gpt-image-* returns base64,
 * which the result's saveToFile() decodes directly.
 */
final class DallEImageGenerator implements ImageGeneratorInterface
{
    private const MODEL = 'gpt-image-2';

    public function __construct(private readonly DallEImageService $dalle) {}

    public function isAvailable(): bool
    {
        return $this->dalle->isAvailable();
    }

    public function getModel(): string
    {
        return self::MODEL;
    }

    public function generateToFile(string $prompt, string $size, string $outputPath): void
    {
        try {
            $result = $this->dalle->generate($prompt, new ImageGenerationOptions(model: self::MODEL, size: $size));
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
