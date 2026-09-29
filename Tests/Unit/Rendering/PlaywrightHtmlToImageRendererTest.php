<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\PlaywrightHtmlToImageRenderer;
use Netresearch\NrRepurpose\Rendering\Process\ProcessResult;
use Netresearch\NrRepurpose\Rendering\Process\ProcessRunnerInterface;
use Netresearch\NrRepurpose\Rendering\Process\SymfonyProcessRunner;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\ProcessTimeoutAssertions;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\SlowExecutable;
use Netresearch\NrRepurpose\Tests\Unit\Rendering\Fixture\RecordingProcessRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class PlaywrightHtmlToImageRendererTest extends TestCase
{
    use ProcessTimeoutAssertions;

    private const string NODE = '/usr/bin/node';

    private const string SCRIPT = '/app/Resources/Private/NodeRenderer/render.cjs';

    private const string OUT_DIR = '/tmp/nrrepurpose-render';

    private const string CHROMIUM = '/usr/bin/chromium';

    private RecordingLogger $logger;

    private function renderer(RecordingProcessRunner $runner): PlaywrightHtmlToImageRenderer
    {
        $this->logger = new RecordingLogger();

        return new PlaywrightHtmlToImageRenderer($runner, $this->logger, self::NODE, self::SCRIPT, self::OUT_DIR, self::CHROMIUM);
    }

    public function testDiagramRenderBuildsAutoHeightTransparentArgvAndFeedsHtmlOnStdin(): void
    {
        $runner = new RecordingProcessRunner();
        $out    = $this->renderer($runner)->render('<html><body>diagram</body></html>', 1200, null, 2.0, true);

        self::assertCount(1, $runner->calls);
        $call = $runner->calls[0];

        self::assertSame(self::NODE, $call['command'][0]);
        self::assertSame(self::SCRIPT, $call['command'][1]);
        self::assertSame(
            ['--width', '1200', '--height', 'auto', '--scale', '2', '--out', $out, '--transparent'],
            array_slice($call['command'], 2),
        );
        self::assertSame('<html><body>diagram</body></html>', $call['stdin']);
        self::assertStringStartsWith(self::OUT_DIR . '/', $out);
        self::assertStringEndsWith('.png', $out);
    }

    public function testStoryRenderBuildsFixedHeightOpaqueArgv(): void
    {
        $runner = new RecordingProcessRunner();
        $out    = $this->renderer($runner)->render('<html></html>', 1080, 1920, 1.0, false);

        self::assertSame(
            ['--width', '1080', '--height', '1920', '--scale', '1', '--out', $out, '--opaque'],
            array_slice($runner->calls[0]['command'], 2),
        );
    }

    public function testPdfRenderBuildsPdfArgvAndWritesAPdfPath(): void
    {
        $runner = new RecordingProcessRunner();
        $out    = $this->renderer($runner)->renderPdf('<html><body>deck</body></html>', 1920);

        self::assertSame(
            ['--width', '1920', '--out', $out, '--opaque', '--pdf'],
            array_slice($runner->calls[0]['command'], 2),
        );
        self::assertSame('<html><body>deck</body></html>', $runner->calls[0]['stdin']);
        self::assertStringEndsWith('.pdf', $out);
    }

    /**
     * Symfony Process forwards only getenv() keys that are also in $_SERVER, so a putenv()
     * in this process never reaches render.cjs and Playwright falls back to its own browser.
     * The configured path has to travel in the process environment passed to the runner.
     */
    public function testChromiumPathIsPassedInTheProcessEnvironment(): void
    {
        $runner = new RecordingProcessRunner();
        $this->renderer($runner)->render('<html></html>', 800, 600, 1.0, false);

        self::assertSame(['CHROMIUM_PATH' => self::CHROMIUM], $runner->calls[0]['env']);
    }

    public function testChromiumPathIsNotPassedViaArgv(): void
    {
        $runner = new RecordingProcessRunner();
        $out    = $this->renderer($runner)->render('<html></html>', 800, 600, 1.0, false);

        self::assertNotContains(self::CHROMIUM, $runner->calls[0]['command']);
        self::assertStringEndsWith('.png', $out);
    }

    /**
     * render.cjs stderr holds the Chromium launch line (profile directory, script path) and
     * the renderer's own error text. The exception message reaches the artifact's
     * error_message, shown to every module user, so stderr goes to the server log only.
     */
    public function testNonZeroExitRaisesAFixedMessageAndLogsStderr(): void
    {
        $stderr = 'browserType.launch: Target closed <launching> /usr/bin/chromium --user-data-dir=/tmp/playwright_chromiumdev_profile-AbC123';
        $runner = new RecordingProcessRunner(new ProcessResult(1, '', $stderr));

        try {
            $this->renderer($runner)->render('<html></html>', 1080, 1920, 1.0, false);
            self::fail('Expected a RenderingException');
        } catch (RenderingException $e) {
            self::assertSame('HTML render failed (exit 1)', $e->getMessage());
            self::assertStringNotContainsString('playwright_chromiumdev_profile', $e->getMessage());
        }

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0]['level']);
        self::assertSame($stderr, $this->logger->records[0]['context']['stderr'] ?? null);
    }

    public function testAnUnwritableOutputDirRaisesAFixedMessageAndLogsThePath(): void
    {
        $dir          = '/proc/nrrepurpose-not-creatable';
        $this->logger = new RecordingLogger();
        $renderer     = new PlaywrightHtmlToImageRenderer(new RecordingProcessRunner(), $this->logger, self::NODE, self::SCRIPT, $dir, self::CHROMIUM);

        try {
            $renderer->render('<html></html>', 1080, 1920, 1.0, false);
            self::fail('Expected a RenderingException');
        } catch (RenderingException $e) {
            self::assertSame('Render output dir not writable', $e->getMessage());
        }

        self::assertCount(1, $this->logger->records);
        self::assertSame($dir, $this->logger->records[0]['context']['path'] ?? null);
    }

    public function testAMissingOutputFileRaisesAFixedMessageAndLogsThePath(): void
    {
        // A successful exit that writes no file.
        $runner = new class implements ProcessRunnerInterface {
            public function run(array $command, ?string $stdin = null, float $timeoutSeconds = 60.0, array $env = []): ProcessResult
            {
                return new ProcessResult(0, '', '');
            }
        };
        $this->logger = new RecordingLogger();
        $renderer     = new PlaywrightHtmlToImageRenderer($runner, $this->logger, self::NODE, self::SCRIPT, self::OUT_DIR, self::CHROMIUM);

        try {
            $renderer->render('<html></html>', 1080, 1920, 1.0, false);
            self::fail('Expected a RenderingException');
        } catch (RenderingException $e) {
            self::assertSame('Renderer produced no PNG', $e->getMessage());
            self::assertStringNotContainsString(self::OUT_DIR, $e->getMessage());
        }

        self::assertCount(1, $this->logger->records);
        self::assertStringStartsWith(self::OUT_DIR . '/', (string) ($this->logger->records[0]['context']['path'] ?? ''));
    }

    /** A render that runs into the timeout, through the real process runner. */
    public function testATimeoutRaisesAFixedMessageAndLogsTheCause(): void
    {
        $slow     = new SlowExecutable();
        $logger   = new RecordingLogger();
        $renderer = new PlaywrightHtmlToImageRenderer(new SymfonyProcessRunner($logger), $logger, $slow->path, self::SCRIPT, sys_get_temp_dir(), self::CHROMIUM, 0.1);

        try {
            self::assertTimeoutIsFixedAndLogged(
                static fn (): string => $renderer->render('<html></html>', 1080, 1920, 1.0, false),
                $logger,
                RenderingException::class,
                'External process timed out',
                1749400501,
            );
        } finally {
            $slow->remove();
        }
    }
}
