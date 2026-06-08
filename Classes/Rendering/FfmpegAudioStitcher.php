<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

use Netresearch\NrRepurpose\Rendering\Process\ProcessRunnerInterface;

/**
 * Concatenates ordered mp3 segments into one mp3 using the ffmpeg concat DEMUXER (stream copy,
 * no re-encode): it writes a temp concat-list file of `file '<abs>'` lines and runs
 * `ffmpeg -f concat -safe 0 -i <list> -c copy -y <out>`. probeDurationSeconds() reads a file's
 * duration via `ffprobe -show_entries format=duration` for WebVTT cue timing. All binaries
 * (ffmpeg/ffprobe) are baked into the DDEV web-build image (Plan 1 Task 2).
 */
final class FfmpegAudioStitcher implements AudioStitcherInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly string $ffmpegBinary = 'ffmpeg',
        private readonly string $ffprobeBinary = 'ffprobe',
        private readonly string $workDir = '',
        private readonly float $timeoutSeconds = 120.0,
    ) {}

    public function concat(array $mp3Paths, string $outPath): string
    {
        if ($mp3Paths === []) {
            throw RenderingException::because('Cannot concat an empty mp3 list', 1749400301);
        }

        $dir = rtrim($this->workDir, '/');
        if ($dir === '') {
            $dir = sys_get_temp_dir();
        }
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw RenderingException::because('Audio work dir not writable: ' . $dir, 1749400302);
        }

        $listPath = $dir . '/concat-' . bin2hex(random_bytes(8)) . '.txt';
        $lines = [];
        foreach ($mp3Paths as $path) {
            // ffmpeg concat-list syntax escapes a single quote as: '\''
            $lines[] = "file '" . str_replace("'", "'\\''", $path) . "'";
        }
        if (file_put_contents($listPath, implode("\n", $lines) . "\n") === false) {
            throw RenderingException::because('Could not write ffmpeg concat list', 1749400303);
        }

        try {
            $result = $this->processRunner->run(
                [
                    $this->ffmpegBinary,
                    '-f', 'concat',
                    '-safe', '0',
                    '-i', $listPath,
                    '-c', 'copy',
                    '-y', $outPath,
                ],
                null,
                $this->timeoutSeconds,
            );
        } finally {
            @unlink($listPath);
        }

        if (!$result->successful()) {
            throw RenderingException::because(
                sprintf('ffmpeg concat failed (exit %d): %s', $result->exitCode, trim($result->stderr)),
                1749400304,
            );
        }
        if (!is_file($outPath)) {
            throw RenderingException::because('ffmpeg produced no output at ' . $outPath, 1749400305);
        }

        return $outPath;
    }

    public function probeDurationSeconds(string $path): float
    {
        $result = $this->processRunner->run(
            [
                $this->ffprobeBinary,
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $path,
            ],
            null,
            $this->timeoutSeconds,
        );

        if (!$result->successful()) {
            throw RenderingException::because(
                sprintf('ffprobe failed (exit %d): %s', $result->exitCode, trim($result->stderr)),
                1749400306,
            );
        }

        $value = trim($result->stdout);
        if ($value === '' || !is_numeric($value)) {
            throw RenderingException::because('ffprobe returned no numeric duration: ' . $value, 1749400307);
        }

        return (float) $value;
    }
}
