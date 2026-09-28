<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\FfmpegAudioStitcher;
use Netresearch\NrRepurpose\Rendering\Process\ProcessResult;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrRepurpose\Tests\Unit\Rendering\Fixture\RecordingProcessRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class FfmpegAudioStitcherTest extends TestCase
{
    private const FFMPEG = '/usr/bin/ffmpeg';

    private const FFPROBE = '/usr/bin/ffprobe';

    private string $tmpDir;

    private RecordingLogger $logger;

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
        $this->logger = new RecordingLogger();

        return new FfmpegAudioStitcher($runner, $this->logger, self::FFMPEG, self::FFPROBE, $this->tmpDir);
    }

    public function testConcatBuildsConcatDemuxerArgvAndWritesAQuotedListFile(): void
    {
        $runner = new RecordingProcessRunner();
        $out    = $this->tmpDir . '/joined.mp3';
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

    /**
     * ffmpeg's stderr names the input files (absolute temp paths). The exception message
     * reaches the podcast's error_message, shown to every module user, so stderr goes to
     * the server log only.
     */
    public function testConcatFailureExitRaisesAFixedMessageAndLogsStderr(): void
    {
        $stderr = $this->tmpDir . '/a.mp3: Invalid data found when processing input';
        $runner = new RecordingProcessRunner(new ProcessResult(1, '', $stderr));

        try {
            $this->stitcher($runner)->concat([$this->tmpDir . '/a.mp3'], $this->tmpDir . '/o.mp3');
            self::fail('Expected a RenderingException');
        } catch (RenderingException $e) {
            self::assertSame('ffmpeg concat failed (exit 1)', $e->getMessage());
            self::assertSame(1749400304, $e->getCode());
        }

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0]['level']);
        self::assertSame($stderr, $this->logger->records[0]['context']['stderr'] ?? null);
    }

    public function testASuccessfulConcatWithoutOutputRaisesAFixedMessageAndLogsThePath(): void
    {
        $out = $this->tmpDir . '/never-written.mp3';

        try {
            $this->stitcher(new RecordingProcessRunner())->concat([$this->tmpDir . '/a.mp3'], $out);
            self::fail('Expected a RenderingException');
        } catch (RenderingException $e) {
            self::assertSame('ffmpeg produced no output', $e->getMessage());
            self::assertSame(1749400305, $e->getCode());
        }

        self::assertCount(1, $this->logger->records);
        self::assertSame($out, $this->logger->records[0]['context']['path'] ?? null);
    }

    public function testAnUncreatableWorkDirRaisesAFixedMessageAndLogsThePath(): void
    {
        $dir          = '/proc/nrrepurpose-not-creatable';
        $this->logger = new RecordingLogger();
        $stitcher     = new FfmpegAudioStitcher(new RecordingProcessRunner(), $this->logger, self::FFMPEG, self::FFPROBE, $dir);

        try {
            $stitcher->concat([$this->tmpDir . '/a.mp3'], $this->tmpDir . '/o.mp3');
            self::fail('Expected a RenderingException');
        } catch (RenderingException $e) {
            self::assertSame('Audio work dir not writable', $e->getMessage());
            self::assertSame(1749400302, $e->getCode());
        }

        self::assertCount(1, $this->logger->records);
        self::assertSame($dir, $this->logger->records[0]['context']['path'] ?? null);
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

    public function testProbeFailureExitRaisesAFixedMessageAndLogsStderr(): void
    {
        $stderr = $this->tmpDir . '/missing.mp3: No such file or directory';
        $runner = new RecordingProcessRunner(new ProcessResult(1, '', $stderr));

        try {
            $this->stitcher($runner)->probeDurationSeconds($this->tmpDir . '/missing.mp3');
            self::fail('Expected a RenderingException');
        } catch (RenderingException $e) {
            self::assertSame('ffprobe failed (exit 1)', $e->getMessage());
            self::assertSame(1749400306, $e->getCode());
        }

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0]['level']);
        self::assertSame($stderr, $this->logger->records[0]['context']['stderr'] ?? null);
    }
}
