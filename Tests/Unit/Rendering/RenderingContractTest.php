<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\AudioStitcherInterface;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\ImageCompositorInterface;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use PHPUnit\Framework\TestCase;

final class RenderingContractTest extends TestCase
{
    public function testExceptionIsRuntimeExceptionWithFactory(): void
    {
        $previous = new \RuntimeException('boom');
        $e = RenderingException::because('render failed', 1749400000, $previous);

        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('render failed', $e->getMessage());
        self::assertSame(1749400000, $e->getCode());
        self::assertSame($previous, $e->getPrevious());
    }

    public function testRendererInterfaceSignatureMatchesContract(): void
    {
        $method = new \ReflectionMethod(HtmlToImageRendererInterface::class, 'render');
        self::assertSame('string', (string) $method->getReturnType());
        $params = $method->getParameters();
        self::assertSame(['html', 'width', 'height', 'deviceScaleFactor', 'transparent'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $params,
        ));
        self::assertTrue($params[2]->allowsNull());
        self::assertSame(1.0, $params[3]->getDefaultValue());
        self::assertFalse($params[4]->getDefaultValue());
    }

    public function testCompositorInterfaceSignatureMatchesContract(): void
    {
        $method = new \ReflectionMethod(ImageCompositorInterface::class, 'overlay');
        self::assertSame('string', (string) $method->getReturnType());
        self::assertSame(['backgroundPng', 'foregroundPng', 'outPath'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters(),
        ));
    }

    public function testStitcherInterfaceSignatureMatchesContract(): void
    {
        $concat = new \ReflectionMethod(AudioStitcherInterface::class, 'concat');
        self::assertSame('string', (string) $concat->getReturnType());
        self::assertSame(['mp3Paths', 'outPath'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $concat->getParameters(),
        ));

        $probe = new \ReflectionMethod(AudioStitcherInterface::class, 'probeDurationSeconds');
        self::assertSame('float', (string) $probe->getReturnType());
        self::assertSame(['path'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $probe->getParameters(),
        ));
    }
}
