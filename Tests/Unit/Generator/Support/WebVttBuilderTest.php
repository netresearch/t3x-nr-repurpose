<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Support;

use Netresearch\NrRepurpose\Generator\Support\WebVttBuilder;
use PHPUnit\Framework\TestCase;

final class WebVttBuilderTest extends TestCase
{
    public function testCueTimesAccumulateFromSegmentDurations(): void
    {
        $builder = new WebVttBuilder();

        $vtt = $builder->build([
            ['speaker' => 'Host A', 'text' => 'Welcome to the show.', 'durationSeconds' => 3.5],
            ['speaker' => 'Host B', 'text' => 'Glad to be here.', 'durationSeconds' => 2.25],
        ]);

        $expected = "WEBVTT\n\n"
            . "1\n"
            . "00:00:00.000 --> 00:00:03.500\n"
            . "Host A: Welcome to the show.\n\n"
            . "2\n"
            . "00:00:03.500 --> 00:00:05.750\n"
            . "Host B: Glad to be here.\n";

        self::assertSame($expected, $vtt);
    }

    public function testTimestampFormattingCrossesMinuteAndHourBoundaries(): void
    {
        $builder = new WebVttBuilder();

        $vtt = $builder->build([
            ['speaker' => 'Host A', 'text' => 'Long intro.', 'durationSeconds' => 3661.0],
            ['speaker' => 'Host B', 'text' => 'Reply.', 'durationSeconds' => 1.0],
        ]);

        self::assertStringContainsString('00:00:00.000 --> 01:01:01.000', $vtt);
        self::assertStringContainsString('01:01:01.000 --> 01:01:02.000', $vtt);
    }

    public function testEmptyDialogueProducesHeaderOnly(): void
    {
        $builder = new WebVttBuilder();

        self::assertSame("WEBVTT\n", $builder->build([]));
    }
}
