<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\FfmpegAudioStitcher;
use Netresearch\NrRepurpose\Rendering\Process\ProcessResult;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Tests\Unit\Rendering\Fixture\RecordingProcessRunner;
use PHPUnit\Framework\TestCase;

final class FfmpegAudioStitcherTest extends TestCase
{
    private const FFMPEG = '/usr/bin/ffmpeg';
    private const FFPROBE = '/usr/bin/ffprobe';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/nrrepurpose-stitch-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o775, true);
        file_put_contents($this->tmpDir . '/a.mp3', 'x');
        file_put_contents($this->tmpDir . '/b.mp3', 'y');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function stitcher(RecordingProcessRunner $runner): FfmpegAudioStitcher
    {
        return new FfmpegAudioStitcher($runner, self::FFMPEG, self::FFPROBE, $this->tmpDir);
    }

    public function testConcatBuildsConcatDemuxerArgvAndWritesAQuotedListFile(): void
    {
        $runner = new RecordingProcessRunner();
        $out = $this->tmpDir . '/joined.mp3';
        // Fake runner won't run ffmpeg, so the output must already exist for the is_file() check.
        file_put_contents($out, 'z');

        $returned = $this->stitcher($runner)->concat(
            [$this->tmpDir . '/a.mp3', $this->tmpDir . '/b.mp3'],
            $out,
        );

        self::assertSame($out, $returned);
        self::assertCount(1, $runner->calls);
        $argv = $runner->calls[0]['command'];

        self::assertSame(self::FFMPEG, $argv[0]);
        self::assertSame(['-f', 'concat', '-safe', '0', '-i'], array_slice($argv, 1, 5));
        $listPath = $argv[6];
        self::assertSame(['-c', 'copy', '-y', $out], array_slice($argv, 7));

        // The list file is unlinked after the call; assert its captured snapshot content.
        $list = $runner->fileSnapshots[$listPath] ?? '';
        self::assertStringContainsString("file '" . $this->tmpDir . "/a.mp3'", $list);
        self::assertStringContainsString("file '" . $this->tmpDir . "/b.mp3'", $list);
    }

    public function testConcatRejectsAnEmptyList(): void
    {
        $this->expectException(RenderingException::class);
        $this->stitcher(new RecordingProcessRunner())->concat([], $this->tmpDir . '/o.mp3');
    }

    public function testConcatFailureExitRaisesRenderingException(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(1, '', 'Invalid data found'));
        $this->expectException(RenderingException::class);
        $this->expectExceptionMessageMatches('/Invalid data found/');
        $this->stitcher($runner)->concat([$this->tmpDir . '/a.mp3'], $this->tmpDir . '/o.mp3');
    }

    public function testProbeDurationBuildsFfprobeArgvAndParsesSeconds(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(0, "5.250000\n", ''));

        $seconds = $this->stitcher($runner)->probeDurationSeconds($this->tmpDir . '/a.mp3');

        self::assertEqualsWithDelta(5.25, $seconds, 0.0001);
        self::assertSame(
            [
                self::FFPROBE,
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $this->tmpDir . '/a.mp3',
            ],
            $runner->calls[0]['command'],
        );
    }

    public function testProbeFailureExitRaisesRenderingException(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(1, '', 'No such file'));
        $this->expectException(RenderingException::class);
        $this->stitcher($runner)->probeDurationSeconds($this->tmpDir . '/missing.mp3');
    }
}
