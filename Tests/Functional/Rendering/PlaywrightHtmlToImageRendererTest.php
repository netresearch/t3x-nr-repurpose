<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

use Netresearch\NrRepurpose\Rendering\PlaywrightHtmlToImageRenderer;
use Netresearch\NrRepurpose\Rendering\Process\SymfonyProcessRunner;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;

final class PlaywrightHtmlToImageRendererTest extends AbstractFunctionalTestCase
{
    private function renderer(): PlaywrightHtmlToImageRenderer
    {
        $script = dirname(__DIR__, 3) . '/Resources/Private/NodeRenderer/render.cjs';
        if (!is_file($script) || !is_dir(dirname($script) . '/node_modules')) {
            self::markTestSkipped('NodeRenderer not installed (run npm ci in Resources/Private/NodeRenderer)');
        }
        if (!is_file('/usr/bin/chromium')) {
            self::markTestSkipped('apt chromium not present at /usr/bin/chromium');
        }

        return new PlaywrightHtmlToImageRenderer(
            new SymfonyProcessRunner(),
            'node',
            $script,
            sys_get_temp_dir() . '/nrrepurpose-func-render',
            '/usr/bin/chromium',
        );
    }

    public function testRendersFixedSizeOpaquePng(): void
    {
        $html = '<!doctype html><html><body style="margin:0">'
            . '<div style="width:100px;height:60px;background:#2F99A4"></div></body></html>';

        $out = $this->renderer()->render($html, 100, 60, 1.0, false);

        self::assertFileExists($out);
        $signature = (string) file_get_contents($out, false, null, 0, 8);
        self::assertSame("\x89PNG\r\n\x1a\n", $signature, 'output is a PNG');

        $size = getimagesize($out);
        self::assertNotFalse($size);
        self::assertSame(100, $size[0]);
        self::assertSame(60, $size[1]);

        @unlink($out);
    }

    public function testTransparentAutoHeightRenderProducesPng(): void
    {
        $html = '<!doctype html><html><head><style>html,body{margin:0;background:transparent}</style></head>'
            . '<body><div style="width:80px;height:40px;background:#FF4D00"></div></body></html>';

        $out = $this->renderer()->render($html, 80, null, 1.0, true);

        self::assertFileExists($out);
        $size = getimagesize($out);
        self::assertNotFalse($size);
        self::assertSame(80, $size[0]);
        self::assertGreaterThan(0, $size[1]);

        @unlink($out);
    }
}
