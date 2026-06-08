<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Image;

use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use Netresearch\NrRepurpose\Rendering\RenderingException;

/**
 * Default ImageGeneratorInterface: delegates to nr-llm's DallEImageService (OpenAI images).
 * DALL-E has no image-to-image, so every variant uses text-to-image from a content-derived prompt.
 */
final class DallEImageGenerator implements ImageGeneratorInterface
{
    public function __construct(private readonly DallEImageService $dalle) {}

    public function isAvailable(): bool
    {
        return $this->dalle->isAvailable();
    }

    public function generateToFile(string $prompt, string $size, string $outputPath): void
    {
        try {
            $result = $this->dalle->generate($prompt, new ImageGenerationOptions(model: 'dall-e-3', size: $size));
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
