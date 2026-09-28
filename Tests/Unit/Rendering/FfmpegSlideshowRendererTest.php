<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\FfmpegSlideshowRenderer;
use Netresearch\NrRepurpose\Rendering\Process\ProcessResult;
use Netresearch\NrRepurpose\Rendering\Process\ProcessRunnerInterface;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * The ffmpeg command the renderer builds. The filter graph is the one that was run
 * with ffmpeg 6.1.2 and 8.1.2 (the demo's version) on three 1080x1920 PNGs: 11.0 s,
 * 275 frames, H.264 yuv420p, the metadata keys readable by exiftool.
 */
final class FfmpegSlideshowRendererTest extends TestCase
{
    /** What the fake ffmpeg prints on a failed run: an input path, as the real one does. */
    public const STDERR = '/var/www/html/var/transient/slide-1.png: No such file or directory';

    /** @var list<list<string>> */
    private array $commands = [];

    private function runner(int $exitCode = 0, bool $writeOutput = true): ProcessRunnerInterface
    {
        return new class ($this->commands, $exitCode, $writeOutput) implements ProcessRunnerInterface {
            /** @param list<list<string>> $commands */
            public function __construct(private array &$commands, private readonly int $exitCode, private readonly bool $writeOutput) {}

            public function run(array $command, ?string $stdin = null, float $timeoutSeconds = 60.0, array $env = []): ProcessResult
            {
                $this->commands[] = $command;
                if ($this->writeOutput) {
                    file_put_contents($command[count($command) - 1], 'mp4');
                }

                return new ProcessResult($this->exitCode, '', $this->exitCode === 0 ? '' : FfmpegSlideshowRendererTest::STDERR);
            }
        };
    }

    public function testTheCommandZoomsEveryImageCrossFadesAndTagsTheFile(): void
    {
        $renderer = new FfmpegSlideshowRenderer($this->runner(), new NullLogger(), '/usr/bin/ffmpeg', sys_get_temp_dir());

        $out = $renderer->render(['/tmp/a.png', '/tmp/b.png', '/tmp/c.png'], 1080, 1920, 4.0, [
            'comment'        => 'AI-generated with nr_repurpose — test',
            'AIGenerated'    => 'true',
            'Digital-Source' => 'x',
        ]);

        self::assertStringEndsWith('.mp4', $out);
        self::assertSame([
            '/usr/bin/ffmpeg', '-loglevel', 'error', '-y',
            '-i', '/tmp/a.png', '-i', '/tmp/b.png', '-i', '/tmp/c.png',
            '-filter_complex',
            "[0:v]scale=2160:3840,zoompan=z='min(zoom+0.000800,1.08)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=100:s=1080x1920:fps=25,setsar=1[v0];"
            . "[1:v]scale=2160:3840,zoompan=z='min(zoom+0.000800,1.08)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=100:s=1080x1920:fps=25,setsar=1[v1];"
            . "[2:v]scale=2160:3840,zoompan=z='min(zoom+0.000800,1.08)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=100:s=1080x1920:fps=25,setsar=1[v2];"
            . '[v0][v1]xfade=transition=fade:duration=0.5:offset=3.50[x1];'
            . '[x1][v2]xfade=transition=fade:duration=0.5:offset=7.00[vout]',
            '-map', '[vout]',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart+use_metadata_tags',
            '-metadata', 'comment=AI-generated with nr_repurpose - test',
            '-metadata', 'AIGenerated=true',
            '-metadata', 'DigitalSource=x',
            $out,
        ], $this->commands[0]);
        unlink($out);
    }

    public function testASingleImageNeedsNoFade(): void
    {
        $filter = (new FfmpegSlideshowRenderer($this->runner(), new NullLogger()))->filter(1, 1080, 1920, 4.0);

        self::assertStringEndsWith(';[v0]null[vout]', $filter);
        self::assertStringNotContainsString('xfade', $filter);
    }

    /**
     * ffmpeg's stderr names the input images (absolute temp paths). The exception message
     * reaches the story artifact's error_message, shown to every module user, so stderr
     * goes to the server log only.
     */
    public function testAFailedRunIsARenderingErrorWithAFixedMessageAndLoggedStderr(): void
    {
        $logger = new RecordingLogger();

        try {
            (new FfmpegSlideshowRenderer($this->runner(1, false), $logger))->render(['/tmp/a.png'], 1080, 1920, 4.0, []);
            self::fail('Expected a RenderingException');
        } catch (RenderingException $e) {
            self::assertSame('ffmpeg slideshow failed (exit 1)', $e->getMessage());
            self::assertSame(1749400402, $e->getCode());
        }

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame(self::STDERR, $logger->records[0]['context']['stderr'] ?? null);
    }

    public function testAFailedRunLeavesNoPartialVideo(): void
    {
        try {
            (new FfmpegSlideshowRenderer($this->runner(1, true), new NullLogger(), 'ffmpeg', sys_get_temp_dir()))->render(['/tmp/a.png'], 1080, 1920, 4.0, []);
            self::fail('Expected a rendering error');
        } catch (RenderingException) {
        }

        $out = $this->commands[0][count($this->commands[0]) - 1];
        self::assertStringEndsWith('.mp4', $out);
        self::assertFileDoesNotExist($out);
    }

    public function testARunWithoutOutputIsARenderingError(): void
    {
        $this->expectException(RenderingException::class);
        $this->expectExceptionCode(1749400403);

        (new FfmpegSlideshowRenderer($this->runner(0, false), new NullLogger()))->render(['/tmp/a.png'], 1080, 1920, 4.0, []);
    }
}
