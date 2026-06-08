<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

interface AudioStitcherInterface
{
    /**
     * @param list<string> $mp3Paths concatenated in order into one mp3 (ffmpeg concat).
     * @return string absolute path of the joined mp3 ($outPath)
     * @throws RenderingException
     */
    public function concat(array $mp3Paths, string $outPath): string;

    /**
     * Duration of an audio file in seconds (ffprobe) — for WebVTT cue times.
     *
     * @throws RenderingException
     */
    public function probeDurationSeconds(string $path): float;
}
