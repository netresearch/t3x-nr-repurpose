<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Pipeline;

use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Pipeline\JobProgress;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StatusRecordingJobRepository;
use PHPUnit\Framework\TestCase;

final class JobProgressTest extends TestCase
{
    public function testStepMapsTheFractionIntoTheBand(): void
    {
        $jobs = new StatusRecordingJobRepository();
        $progress = new JobProgress($jobs, 42, 30.0, 65.0);

        $progress->step('Podcast: writing script', 0.0);
        $progress->step('Podcast: voicing segment 1/2', 0.5);
        $progress->step('Podcast: stitching audio', 1.0);

        self::assertSame([30, 48, 65], $jobs->progresses());
        self::assertSame(
            ['Podcast: writing script', 'Podcast: voicing segment 1/2', 'Podcast: stitching audio'],
            $jobs->steps(),
        );
        foreach ($jobs->calls as $call) {
            self::assertSame(42, $call['jobUid']);
            self::assertSame(JobStatus::Generating, $call['status']);
        }
    }

    public function testStepClampsOutOfRangeFractionsToTheBandEdges(): void
    {
        $jobs = new StatusRecordingJobRepository();
        $progress = new JobProgress($jobs, 7, 30.0, 100.0);

        $progress->step('below', -0.5);
        $progress->step('above', 1.5);

        self::assertSame([30, 100], $jobs->progresses());
    }

    public function testStepRoundsToTheNearestIntegerPercent(): void
    {
        $jobs = new StatusRecordingJobRepository();
        // Band of the middle one of three generators: 30 + 70 * [1/3, 2/3].
        $progress = new JobProgress($jobs, 7, 30.0 + 70.0 / 3, 30.0 + 140.0 / 3);

        $progress->step('start', 0.0);
        $progress->step('end', 1.0);

        self::assertSame([53, 77], $jobs->progresses());
    }
}
