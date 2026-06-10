<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

use Netresearch\NrRepurpose\Rendering\FfmpegAudioStitcher;
use Netresearch\NrRepurpose\Rendering\Process\SymfonyProcessRunner;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Process\Process;

final class FfmpegAudioStitcherTest extends AbstractFunctionalTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $ffmpeg = new Process(['ffmpeg', '-version']);
        if ($ffmpeg->run() !== 0) {
            self::markTestSkipped('ffmpeg not available');
        }
        $this->tmpDir = sys_get_temp_dir() . '/nrrepurpose-func-stitch-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        // $tmpDir stays uninitialized when setUp() skipped (ffmpeg not available).
        if (isset($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function makeTone(string $path, float $seconds): void
    {
        $proc = new Process([
            'ffmpeg', '-y',
            '-f', 'lavfi',
            '-i', 'sine=frequency=440:duration=' . $seconds,
            '-c:a', 'libmp3lame', '-q:a', '9',
            $path,
        ]);
        if ($proc->run() !== 0) {
            self::markTestSkipped('ffmpeg cannot encode mp3 (libmp3lame missing): ' . $proc->getErrorOutput());
        }
    }

    public function testConcatTwoTonesYieldsApproxSummedDuration(): void
    {
        $a = $this->tmpDir . '/a.mp3';
        $b = $this->tmpDir . '/b.mp3';
        $out = $this->tmpDir . '/joined.mp3';
        $this->makeTone($a, 1.0);
        $this->makeTone($b, 2.0);

        $stitcher = new FfmpegAudioStitcher(new SymfonyProcessRunner(), 'ffmpeg', 'ffprobe', $this->tmpDir);

        $result = $stitcher->concat([$a, $b], $out);
        self::assertSame($out, $result);
        self::assertFileExists($out);

        $duration = $stitcher->probeDurationSeconds($out);
        self::assertEqualsWithDelta(3.0, $duration, 0.5);

        self::assertEqualsWithDelta(1.0, $stitcher->probeDurationSeconds($a), 0.3);
        self::assertEqualsWithDelta(2.0, $stitcher->probeDurationSeconds($b), 0.3);
    }
}
