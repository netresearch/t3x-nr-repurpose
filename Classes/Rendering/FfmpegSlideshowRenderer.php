<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

use Netresearch\NrRepurpose\Provenance\AiProvenance;
use Netresearch\NrRepurpose\Rendering\Process\ProcessRunnerInterface;
use Throwable;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Renders the slideshow with one ffmpeg call: every image is scaled to twice the
 * output size and zoomed in by 8 % over its time (zoompan, centred), neighbouring
 * images cross-fade for half a second (xfade), and the result is encoded as H.264 in
 * yuv420p with the moov atom at the front (plays in every browser, starts before it is
 * fully loaded). The metadata goes into the MP4 as keys (use_metadata_tags), which
 * exiftool and ffprobe read.
 */
final readonly class FfmpegSlideshowRenderer implements SlideshowRendererInterface
{
    public const FPS = 25;

    public const FADE_SECONDS = 0.5;

    private const ZOOM = 0.08;

    public function __construct(
        private ProcessRunnerInterface $processRunner,
        private string $ffmpegBinary = 'ffmpeg',
        private string $workDir = '',
        private float $timeoutSeconds = 300.0,
    ) {}

    public function render(array $imagePaths, int $width, int $height, float $secondsPerImage, array $metadata): string
    {
        $dir = rtrim($this->workDir, '/');
        if ($dir === '') {
            $dir = sys_get_temp_dir();
        }

        if (!is_dir($dir)) {
            GeneralUtility::mkdir_deep($dir);
        }

        $out     = $dir . '/slideshow-' . bin2hex(random_bytes(8)) . '.mp4';
        $command = [$this->ffmpegBinary, '-loglevel', 'error', '-y'];
        foreach ($imagePaths as $path) {
            $command[] = '-i';
            $command[] = $path;
        }

        $command = [
            ...$command,
            '-filter_complex', $this->filter(count($imagePaths), $width, $height, $secondsPerImage),
            '-map', '[vout]',
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '23',
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart+use_metadata_tags',
        ];
        foreach ($metadata as $key => $value) {
            $command[] = '-metadata';
            $command[] = preg_replace('/[^A-Za-z0-9]/', '', $key) . '=' . AiProvenance::ascii($value);
        }

        $command[] = $out;

        try {
            $result = $this->processRunner->run($command, null, $this->timeoutSeconds);
        } catch (Throwable $e) {
            // A timeout can leave a partial file behind.
            $this->removeOutput($out);

            throw $e;
        }

        if (!$result->successful()) {
            $this->removeOutput($out);

            throw RenderingException::because(
                sprintf('ffmpeg slideshow failed (exit %d): %s', $result->exitCode, trim($result->stderr)),
                1749400402,
            );
        }

        if (!is_file($out)) {
            throw RenderingException::because('ffmpeg produced no video at ' . $out, 1749400403);
        }

        return $out;
    }

    /** Removes what a failed run wrote; the caller only ever gets a complete video. */
    private function removeOutput(string $out): void
    {
        if (is_file($out)) {
            // $out is this renderer's own temp path (random name in its work dir), never user input.
            unlink($out); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }
    }

    /**
     * The filter graph: one zoompan chain per image ([v0] … [vN]), then xfade from the
     * first to the last, each fade starting half a second before the image ends.
     */
    public function filter(int $count, int $width, int $height, float $secondsPerImage): string
    {
        $frames = max(1, (int) round($secondsPerImage * self::FPS));
        $step   = sprintf('%.6F', self::ZOOM / $frames);

        $chains = [];
        for ($i = 0; $i < $count; ++$i) {
            $chains[] = sprintf(
                "[%d:v]scale=%d:%d,zoompan=z='min(zoom+%s,%s)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=%d:s=%dx%d:fps=%d,setsar=1[v%d]",
                $i,
                2 * $width,
                2 * $height,
                $step,
                sprintf('%.2F', 1 + self::ZOOM),
                $frames,
                $width,
                $height,
                self::FPS,
                $i,
            );
        }

        if ($count === 1) {
            return $chains[0] . ';[v0]null[vout]';
        }

        $previous = 'v0';
        for ($i = 1; $i < $count; ++$i) {
            $label    = $i === $count - 1 ? 'vout' : 'x' . $i;
            $offset   = sprintf('%.2F', $i * ($frames / self::FPS - self::FADE_SECONDS));
            $chains[] = sprintf('[%s][v%d]xfade=transition=fade:duration=%s:offset=%s[%s]', $previous, $i, sprintf('%.1F', self::FADE_SECONDS), $offset, $label);
            $previous = $label;
        }

        return implode(';', $chains);
    }
}
