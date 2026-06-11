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
    }

    public function testOutputUsesForegroundDimensionsAndBackgroundCoversWithoutDistortion(): void
    {
        // The foreground is the design canvas (a tall 1:2 portrait); the background is a
        // mismatched 2:1 landscape. The output must take the FOREGROUND's dimensions and the
        // background must be scaled to cover them — never the other way round.
        $bg = $this->makeOpaquePng(40, 20, 10, 20, 200);
        $fg = $this->makeMostlyTransparentPng(20, 40);
        $out = $this->tmpDir . '/out-cover.png';

        (new GdImageCompositor())->overlay($bg, $fg, $out);

        $size = getimagesize($out);
        self::assertNotFalse($size);
        self::assertSame(20, $size[0], 'output width must equal the foreground width');
        self::assertSame(40, $size[1], 'output height must equal the foreground height');

        $result = imagecreatefrompng($out);
        // Where the foreground is transparent, the cover-scaled background shows through.
        $centre = imagecolorsforindex($result, imagecolorat($result, 10, 20));
        self::assertSame(200, $centre['blue']);
        // The single opaque foreground pixel is painted on top of the background.
        $corner = imagecolorsforindex($result, imagecolorat($result, 0, 0));
        self::assertSame(255, $corner['red']);
        self::assertSame(0, $corner['blue']);
    }

    public function testMissingBackgroundRaisesRenderingException(): void
    {
        $fg = $this->makeMostlyTransparentPng(4, 4);

        $this->expectException(RenderingException::class);
        (new GdImageCompositor())->overlay($this->tmpDir . '/does-not-exist.png', $fg, $this->tmpDir . '/o.png');
    }

    public function testRequiredBytesBudgetsBackgroundPlusTwoForegroundSizedImages(): void
    {
        // background + (foreground + canvas at foreground size), 8 bytes/pixel.
        self::assertSame((100 * 50 + 2 * 200 * 80) * 8, GdImageCompositor::requiredBytes(100, 50, 200, 80));
    }

    public function testMemoryLimitParsesIniShorthandAndUnlimited(): void
    {
        self::assertSame(256 * 1024 ** 2, GdImageCompositor::memoryLimitBytes('256M'));
        self::assertSame(1024 ** 3, GdImageCompositor::memoryLimitBytes('1G'));
        self::assertSame(64 * 1024, GdImageCompositor::memoryLimitBytes('64K'));
        self::assertSame(123456, GdImageCompositor::memoryLimitBytes('123456'));
        self::assertSame(PHP_INT_MAX, GdImageCompositor::memoryLimitBytes('-1'));
    }

    public function testOversizedCompositeFailsTheArtifactInsteadOfFatallingTheWorker(): void
    {
        // A 1536x1024 background onto a 2400x3696 render (real gpt-image-2 +
        // 2x-scaled HTML sizes) projects ~148MB — more than the 64MB budget here.
        // The guard throws the catchable RenderingException instead of letting GD
        // fatal the worker mid-message (the phpunit runtime is unlimited, so the
        // budget is injected; overlay() wires the real ini-based budget).
        try {
            GdImageCompositor::assertFits(1536, 1024, 2400, 3696, 64 * 1024 ** 2);
            self::fail('Expected the memory guard to reject the oversized composite');
        } catch (RenderingException $e) {
            self::assertStringContainsString('memory', $e->getMessage());
        }

        // The same pair fits comfortably into a 1G budget — no exception.
        GdImageCompositor::assertFits(1536, 1024, 2400, 3696, 1024 ** 3);
        self::assertTrue(true);
    }
}
