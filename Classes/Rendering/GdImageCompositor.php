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
    /**
     * GD holds roughly this many bytes per pixel for a truecolor image (4 channel
     * bytes plus internal row/struct overhead; empirically 5–8 — we budget high).
     */
    private const GD_BYTES_PER_PIXEL = 8;

    public function overlay(string $backgroundPng, string $foregroundPng, string $outPath): string
    {
        // Pre-flight from the PNG headers (no decode): background + foreground +
        // output canvas live in memory simultaneously. Without this guard an
        // oversized pair fatals the PHP worker mid-message — unreachable by any
        // try/catch — and leaves the job in an hourly crash-redeliver loop.
        // Failing here surfaces as a regular failed artifact instead.
        $this->assertEnoughMemory($backgroundPng, $foregroundPng);

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

    /**
     * Projected GD memory for compositing the two images (background + foreground
     * + a canvas at foreground size), in bytes. Pure so it is unit-testable.
     */
    public static function requiredBytes(int $bgW, int $bgH, int $fgW, int $fgH): int
    {
        return ($bgW * $bgH + 2 * $fgW * $fgH) * self::GD_BYTES_PER_PIXEL;
    }

    /**
     * Parse a php.ini memory_limit value ('256M', '1G', bytes, or -1) into bytes;
     * -1 (unlimited) returns PHP_INT_MAX. Pure so it is unit-testable.
     */
    public static function memoryLimitBytes(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        $value = (int) $limit;

        return match (strtoupper(substr($limit, -1))) {
            'G' => $value * 1024 ** 3,
            'M' => $value * 1024 ** 2,
            'K' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Throw the catchable RenderingException when the projected composite does not
     * fit the given memory budget. Pure (budget injected) so it is unit-testable —
     * the worker's real budget comes from assertEnoughMemory().
     */
    public static function assertFits(int $bgW, int $bgH, int $fgW, int $fgH, int $availableBytes): void
    {
        $needed = self::requiredBytes($bgW, $bgH, $fgW, $fgH);
        if ($needed > $availableBytes) {
            throw RenderingException::because(sprintf(
                'Compositing %dx%d onto %dx%d needs ~%dMB but only ~%dMB PHP memory is free '
                . '— raise the worker memory_limit or reduce the image/render size',
                $bgW,
                $bgH,
                $fgW,
                $fgH,
                (int) ceil($needed / 1024 ** 2),
                max(0, (int) floor($availableBytes / 1024 ** 2)),
            ), 1749400208);
        }
    }

    private function assertEnoughMemory(string $backgroundPng, string $foregroundPng): void
    {
        $bg = @getimagesize($backgroundPng);
        $fg = @getimagesize($foregroundPng);
        if ($bg === false || $fg === false) {
            return; // not a readable image — load() raises the precise error
        }

        self::assertFits(
            $bg[0],
            $bg[1],
            $fg[0],
            $fg[1],
            self::memoryLimitBytes((string) ini_get('memory_limit')) - memory_get_usage(true),
        );
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
