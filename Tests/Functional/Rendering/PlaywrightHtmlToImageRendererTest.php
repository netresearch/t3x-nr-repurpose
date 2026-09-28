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
     * The templates @import their web fonts from Google Fonts, but the renderer has no
     * network access: the @import must be served from the fonts bundled in
     * Resources/Private/Fonts. The render with the @import has to match a render that
     * embeds the bundled Raleway file itself, and differ from the fallback font.
     */
    public function testTemplateWebFontsComeFromTheBundledFiles(): void
    {
        $text = '<p style="margin:0;font:700 40px/1 Raleway,monospace">Raleway 48 Mio. Äöü</p>';
        $page = static fn (string $style): string => '<!doctype html><html><head><style>' . $style
            . 'html,body{margin:0;background:#fff}</style></head><body>' . $text . '</body></html>';
        $bundle = dirname(__DIR__, 3) . '/Resources/Private/Fonts/Raleway/Raleway[wght].ttf';
        self::assertFileExists($bundle);

        $imported = $this->pixels($page("@import url('https://fonts.googleapis.com/css2?family=Raleway:wght@600;700&family=Open+Sans:wght@400;600&display=swap');"));
        $embedded = $this->pixels($page("@font-face{font-family:'Raleway';font-weight:100 900;src:url(data:font/ttf;base64,"
            . base64_encode((string) file_get_contents($bundle)) . ") format('truetype')}"));
        $fallback = $this->pixels($page(''));

        self::assertNotSame($fallback, $embedded, 'the bundled font renders like the fallback, the comparison proves nothing');
        self::assertSame($embedded, $imported, 'the @import did not render in the bundled Raleway');
    }

    /** Renders $html at 600x60 and returns a hash of its decoded pixels. */
    private function pixels(string $html): string
    {
        $out   = $this->renderer()->render($html, 600, 60, 1.0, false);
        $image = imagecreatefrompng($out);
        @unlink($out);
        self::assertNotFalse($image);

        $pixels = '';
        for ($y = 0; $y < imagesy($image); ++$y) {
            for ($x = 0; $x < imagesx($image); ++$x) {
                $pixels .= pack('N', imagecolorat($image, $x, $y));
            }
        }

        return hash('sha256', $pixels);
    }

    /**
     * No request may leave the renderer, not even for web fonts (they are bundled): an injected
     * <img>, CSS background, stylesheet or prefetch hint to a local address must not reach it.
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
            // Chromium sends prefetches without passing them through Playwright's request routing.
            . '<link rel="prefetch" href="http://' . $address . '/prefetch">'
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
