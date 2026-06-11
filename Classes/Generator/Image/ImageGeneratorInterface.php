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
     * The image model id this generator calls (e.g. "gpt-image-2"). Generators record it
     * in the artifact metadata so the result view shows the model that actually ran.
     */
    public function getModel(): string;

    /**
     * Editor-maintained style preamble for every image prompt ('' when none) — the system
     * prompt of the steering nr-llm Configuration record. Generators prepend it when
     * building their image prompts, BEFORE recording the prompt in artifact metadata,
     * so the recorded prompt stays the exact text that was sent.
     */
    public function getPromptPreamble(): string;

    /**
     * Generate an image from $prompt at the given $size (e.g. 1024x1024, 1792x1024, 1024x1792)
     * and write it to $outputPath (PNG).
     *
     * @throws \Netresearch\NrRepurpose\Rendering\RenderingException on generation failure
     */
    public function generateToFile(string $prompt, string $size, string $outputPath): void;
}
