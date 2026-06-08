<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Image;

/**
 * Thin, provider-agnostic seam over nr-llm's (final) image services so generators and their
 * unit tests do not depend on a concrete class. The default implementation wraps DALL-E
 * (the only configured provider here); a fal.ai-backed implementation could be swapped in.
 */
interface ImageGeneratorInterface
{
    public function isAvailable(): bool;

    /**
     * Generate an image from $prompt at the given $size (e.g. 1024x1024, 1792x1024, 1024x1792)
     * and write it to $outputPath (PNG).
     *
     * @throws \Netresearch\NrRepurpose\Rendering\RenderingException on generation failure
     */
    public function generateToFile(string $prompt, string $size, string $outputPath): void;
}
