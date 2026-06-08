<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

/**
 * Overlays a transparent foreground PNG (the exact HTML-rendered text/label layer) onto a
 * background PNG (an AI-generated image) using GD — Imagick is NOT installed in this stack.
 *
 * The FOREGROUND defines the output canvas: it is the layout designed at an exact aspect ratio
 * (e.g. the 1080x1920 9:16 story, or a rendered diagram). The background image — whose aspect
 * ratio rarely matches (gpt-image-1 only emits 1:1, 3:2, 2:3) — is scaled to COVER that canvas
 * (centre-cropped, no distortion), then the foreground is alpha-composited on top so transparent
 * areas reveal the background. The result is written as a PNG at the foreground's dimensions.
 */
final class GdImageCompositor implements ImageCompositorInterface
{
    public function overlay(string $backgroundPng, string $foregroundPng, string $outPath): string
    {
        // GdImage instances are freed by the garbage collector when they go out of scope
        // (imagedestroy() is a deprecated no-op since PHP 8.0).
        $background = $this->load($backgroundPng);
        $foreground = $this->load($foregroundPng);

        $targetWidth = imagesx($foreground);
        $targetHeight = imagesy($foreground);
        $bgWidth = imagesx($background);
        $bgHeight = imagesy($background);

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        // Start from a transparent canvas so a foreground with alpha keeps it where nothing
        // else paints; the cover-scaled (opaque) background then fills the whole frame.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        [$srcX, $srcY, $srcW, $srcH] = $this->coverSourceRect($bgWidth, $bgHeight, $targetWidth, $targetHeight);
        if (imagecopyresampled($canvas, $background, 0, 0, $srcX, $srcY, $targetWidth, $targetHeight, $srcW, $srcH) === false) {
            throw RenderingException::because('GD background cover-resample failed', 1749400201);
        }

        // Alpha-composite the foreground at full size on top of the background.
        if (imagecopy($canvas, $foreground, 0, 0, 0, 0, $targetWidth, $targetHeight) === false) {
            throw RenderingException::because('GD foreground overlay copy failed', 1749400207);
        }

        $dir = \dirname($outPath);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw RenderingException::because('Compositor output dir not writable: ' . $dir, 1749400202);
        }
        if (imagepng($canvas, $outPath) === false) {
            throw RenderingException::because('GD could not write PNG to ' . $outPath, 1749400203);
        }

        return $outPath;
    }

    /**
     * Largest centred sub-rectangle of the source (background) that matches the target aspect
     * ratio — resampling this region to fill the target yields a distortion-free "cover" fit.
     *
     * @return array{0:int,1:int,2:int,3:int} [srcX, srcY, srcWidth, srcHeight]
     */
    private function coverSourceRect(int $srcW, int $srcH, int $targetW, int $targetH): array
    {
        // Compare aspect ratios via cross-multiplication to stay in integer arithmetic.
        if ($srcW * $targetH > $targetW * $srcH) {
            // Source is wider than the target → crop the sides.
            $cropW = (int) round($srcH * $targetW / $targetH);

            return [(int) round(($srcW - $cropW) / 2), 0, max(1, $cropW), $srcH];
        }

        // Source is taller than (or equal to) the target → crop top and bottom.
        $cropH = (int) round($srcW * $targetH / $targetW);

        return [0, (int) round(($srcH - $cropH) / 2), $srcW, max(1, $cropH)];
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
