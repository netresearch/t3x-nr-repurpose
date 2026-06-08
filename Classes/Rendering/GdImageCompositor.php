<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

/**
 * Overlays a transparent foreground PNG (the exact HTML-rendered text/label layer) onto a
 * background PNG (an AI-generated image) using GD — Imagick is NOT installed in this stack.
 * The foreground is resized to the background dimensions when they differ; alpha is preserved
 * so transparent foreground areas reveal the background, and the result is written as a PNG.
 */
final class GdImageCompositor implements ImageCompositorInterface
{
    public function overlay(string $backgroundPng, string $foregroundPng, string $outPath): string
    {
        $background = $this->load($backgroundPng);
        try {
            $foreground = $this->load($foregroundPng);
            try {
                $bgWidth = imagesx($background);
                $bgHeight = imagesy($background);
                $fgWidth = imagesx($foreground);
                $fgHeight = imagesy($foreground);

                // Keep the foreground alpha during the copy and in the output.
                imagealphablending($background, true);
                imagesavealpha($background, true);

                if ($fgWidth === $bgWidth && $fgHeight === $bgHeight) {
                    $ok = imagecopy($background, $foreground, 0, 0, 0, 0, $bgWidth, $bgHeight);
                } else {
                    $ok = imagecopyresampled(
                        $background,
                        $foreground,
                        0, 0, 0, 0,
                        $bgWidth, $bgHeight,
                        $fgWidth, $fgHeight,
                    );
                }
                if ($ok === false) {
                    throw RenderingException::because('GD overlay copy failed', 1749400201);
                }

                $dir = \dirname($outPath);
                if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
                    throw RenderingException::because('Compositor output dir not writable: ' . $dir, 1749400202);
                }
                if (imagepng($background, $outPath) === false) {
                    throw RenderingException::because('GD could not write PNG to ' . $outPath, 1749400203);
                }
            } finally {
                imagedestroy($foreground);
            }
        } finally {
            imagedestroy($background);
        }

        return $outPath;
    }

    private function load(string $path): \GdImage
    {
        if (!is_file($path)) {
            throw RenderingException::because('Compositor input PNG not found: ' . $path, 1749400204);
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw RenderingException::because('Compositor input PNG unreadable: ' . $path, 1749400205);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw RenderingException::because('Compositor input is not a valid image: ' . $path, 1749400206);
        }

        return $image;
    }
}
