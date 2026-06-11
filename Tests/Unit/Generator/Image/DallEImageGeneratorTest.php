<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Image;

use Netresearch\NrRepurpose\Generator\Image\DallEImageGenerator;
use PHPUnit\Framework\TestCase;

final class DallEImageGeneratorTest extends TestCase
{
    public function testModelConstantPinsTheGptImage2Fallback(): void
    {
        // getModel() resolves the effective model from nr-llm's Model registry and falls
        // back to this constant; asserting the constant pins the fallback without
        // reflection (nr-llm's DallEImageService is final, not mockable).
        //
        // The method_exists() fallback branch inside getModel() is intentionally not
        // unit-tested: exercising it needs a DallEImageService instance, the class is
        // final (unmockable), and a reflection-created uninitialized instance would make
        // the test outcome depend on whether the installed nr-llm already ships
        // resolveDefaultModel(). The ImageGeneratorInterface seam exists precisely so
        // every other test stubs the resolved model instead.
        self::assertSame('gpt-image-2', DallEImageGenerator::MODEL);
    }
}
