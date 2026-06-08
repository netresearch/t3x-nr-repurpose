<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\PlaywrightHtmlToImageRenderer;
use Netresearch\NrRepurpose\Rendering\Process\ProcessResult;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Tests\Unit\Rendering\Fixture\RecordingProcessRunner;
use PHPUnit\Framework\TestCase;

final class PlaywrightHtmlToImageRendererTest extends TestCase
{
    private const NODE = '/usr/bin/node';
    private const SCRIPT = '/app/Resources/Private/NodeRenderer/render.cjs';
    private const OUT_DIR = '/tmp/nrrepurpose-render';
    private const CHROMIUM = '/usr/bin/chromium';

    private function renderer(RecordingProcessRunner $runner): PlaywrightHtmlToImageRenderer
    {
        return new PlaywrightHtmlToImageRenderer($runner, self::NODE, self::SCRIPT, self::OUT_DIR, self::CHROMIUM);
    }

    public function testDiagramRenderBuildsAutoHeightTransparentArgvAndFeedsHtmlOnStdin(): void
    {
        $runner = new RecordingProcessRunner();
        $out = $this->renderer($runner)->render('<html><body>diagram</body></html>', 1200, null, 2.0, true);

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
        $out = $this->renderer($runner)->render('<html></html>', 1080, 1920, 1.0, false);

        self::assertSame(
            ['--width', '1080', '--height', '1920', '--scale', '1', '--out', $out, '--opaque'],
            array_slice($runner->calls[0]['command'], 2),
        );
    }

    public function testChromiumPathIsNotPassedViaArgv(): void
    {
        $runner = new RecordingProcessRunner();
        $out = $this->renderer($runner)->render('<html></html>', 800, 600, 1.0, false);

        self::assertNotContains(self::CHROMIUM, $runner->calls[0]['command']);
        self::assertStringEndsWith('.png', $out);
    }

    public function testNonZeroExitRaisesRenderingExceptionWithStderr(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(1, '', 'chromium crashed'));

        $this->expectException(RenderingException::class);
        $this->expectExceptionMessageMatches('/chromium crashed/');

        $this->renderer($runner)->render('<html></html>', 1080, 1920, 1.0, false);
    }
}
