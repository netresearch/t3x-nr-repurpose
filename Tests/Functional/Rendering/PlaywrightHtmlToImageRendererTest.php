<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

use Netresearch\NrRepurpose\Rendering\PlaywrightHtmlToImageRenderer;
use Netresearch\NrRepurpose\Rendering\Process\SymfonyProcessRunner;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Log\NullLogger;

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
            new NullLogger(),
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

    /**
     * The body HTML is LLM output derived from a fetched page, so an injected script must
     * not run: here it would remove the red square before the screenshot.
     */
    public function testInjectedScriptDoesNotRun(): void
    {
        $html = '<!doctype html><html><body style="margin:0;background:#fff">'
            . '<div id="victim" style="width:10px;height:10px;background:#ff0000"></div>'
            . '<script>document.getElementById("victim").remove()</script>'
            . '</body></html>';

        $out = $this->renderer()->render($html, 10, 10, 1.0, false);

        $image = imagecreatefrompng($out);
        self::assertNotFalse($image);
        $rgb = imagecolorat($image, 5, 5);
        self::assertSame([255, 0, 0], [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF], 'script ran and removed the element');

        @unlink($out);
    }

    /**
     * No request may leave the renderer for anything but the template web fonts: an injected
     * <img> or CSS background to a local address must not reach it.
     */
    public function testInjectedResourceRequestsDoNotLeaveTheRenderer(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        $address = (string) stream_socket_get_name($server, false);

        $html = '<!doctype html><html><body style="margin:0">'
            . '<img src="http://' . $address . '/img">'
            . '<div style="width:10px;height:10px;background:url(http://' . $address . '/bg)"></div>'
            . '<link rel="stylesheet" href="http://' . $address . '/css">'
            . '</body></html>';

        $renderer = $this->renderer();
        $out      = null;
        try {
            $out = $renderer->render($html, 20, 20, 1.0, false);
        } catch (RenderingException) {
            // A renderer that does send the request waits on this never-answering socket
            // until it times out; the assertion below reports that as the real cause.
        }

        // The render is synchronous: any connection it opened is queued on the socket by now.
        $connection = @stream_socket_accept($server, 0);
        fclose($server);
        if ($out !== null) {
            @unlink($out);
        }

        self::assertFalse($connection, 'renderer opened a connection to ' . $address);
        self::assertNotNull($out, 'render failed although no request left the renderer');
    }
}
