<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\GdImageCompositor;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use PHPUnit\Framework\TestCase;

final class GdImageCompositorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        if (!\extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is required for the compositor');
        }
        $this->tmpDir = sys_get_temp_dir() . '/nrrepurpose-gd-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function makeOpaquePng(int $w, int $h, int $r, int $g, int $b): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        $path = $this->tmpDir . '/bg-' . bin2hex(random_bytes(3)) . '.png';
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    private function makeMostlyTransparentPng(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); // fully transparent
        imagesetpixel($im, 0, 0, imagecolorallocate($im, 255, 0, 0));      // one opaque red pixel
        $path = $this->tmpDir . '/fg-' . bin2hex(random_bytes(3)) . '.png';
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    public function testOverlayKeepsBackgroundWhereForegroundIsTransparentAndPaintsOpaquePixels(): void
    {
        $bg = $this->makeOpaquePng(20, 30, 0, 0, 255);
        $fg = $this->makeMostlyTransparentPng(20, 30);
        $out = $this->tmpDir . '/out.png';

        $returned = (new GdImageCompositor())->overlay($bg, $fg, $out);

        self::assertSame($out, $returned);
        self::assertFileExists($out);

        $size = getimagesize($out);
        self::assertNotFalse($size);
        self::assertSame(20, $size[0]);
        self::assertSame(30, $size[1]);

        $result = imagecreatefrompng($out);
        $corner = imagecolorsforindex($result, imagecolorat($result, 0, 0));
        self::assertSame(255, $corner['red']);
        self::assertSame(0, $corner['blue']);
        $centre = imagecolorsforindex($result, imagecolorat($result, 10, 15));
        self::assertSame(255, $centre['blue']);
        self::assertSame(0, $centre['red']);
        imagedestroy($result);
    }

    public function testForegroundIsResizedToBackgroundDimensions(): void
    {
        $bg = $this->makeOpaquePng(40, 40, 10, 20, 30);
        $fg = $this->makeMostlyTransparentPng(8, 8);
        $out = $this->tmpDir . '/out-resized.png';

        (new GdImageCompositor())->overlay($bg, $fg, $out);

        $size = getimagesize($out);
        self::assertNotFalse($size);
        self::assertSame(40, $size[0]);
        self::assertSame(40, $size[1]);
    }

    public function testMissingBackgroundRaisesRenderingException(): void
    {
        $fg = $this->makeMostlyTransparentPng(4, 4);

        $this->expectException(RenderingException::class);
        (new GdImageCompositor())->overlay($this->tmpDir . '/does-not-exist.png', $fg, $this->tmpDir . '/o.png');
    }
}
