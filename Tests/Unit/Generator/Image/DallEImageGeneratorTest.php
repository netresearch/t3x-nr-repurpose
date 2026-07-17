<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Image;

use Netresearch\NrRepurpose\Generator\Image\DallEImageGenerator;
use PHPUnit\Framework\TestCase;

final class DallEImageGeneratorTest extends TestCase
{
    public function testModelConstantPinsTheGptImage2Fallback(): void
    {
        // getModel() delegates to nr-llm's DallEImageService::resolveModelForConfiguration(),
        // falling back to this constant; asserting the constant pins the fallback without
        // reflection (nr-llm's DallEImageService is final, not mockable). The
        // ImageGeneratorInterface seam exists precisely so every other test stubs the
        // resolved model instead.
        self::assertSame('gpt-image-2', DallEImageGenerator::MODEL);
    }
}
