<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

/**
 * Builds a WebVTT subtitle document from a sequence of dialogue segments and their
 * measured (ffprobe) durations. Cue start = sum of all prior durations; no provider call.
 */
final class WebVttBuilder
{
    /**
     * @param list<array{speaker: string, text: string, durationSeconds: float}> $segments
     */
    public function build(array $segments): string
    {
        $out = 'WEBVTT' . "\n";
        $cursor = 0.0;
        $index = 1;

        foreach ($segments as $segment) {
            $start = $cursor;
            $end = $cursor + $segment['durationSeconds'];
            $out .= "\n" . $index . "\n";
            $out .= $this->formatTimestamp($start) . ' --> ' . $this->formatTimestamp($end) . "\n";
            $out .= $segment['speaker'] . ': ' . $segment['text'] . "\n";
            $cursor = $end;
            $index++;
        }

        return $out;
    }

    private function formatTimestamp(float $seconds): string
    {
        $milliseconds = (int) round($seconds * 1000);
        $hours = intdiv($milliseconds, 3_600_000);
        $milliseconds -= $hours * 3_600_000;
        $minutes = intdiv($milliseconds, 60_000);
        $milliseconds -= $minutes * 60_000;
        $secs = intdiv($milliseconds, 1000);
        $milliseconds -= $secs * 1000;

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $milliseconds);
    }
}
