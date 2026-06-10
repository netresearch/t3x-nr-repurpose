<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Image;

use Netresearch\NrRepurpose\Generator\Image\DallEImageGenerator;
use PHPUnit\Framework\TestCase;

final class DallEImageGeneratorTest extends TestCase
{
    public function testModelConstantPinsTheGptImage2Model(): void
    {
        // getModel() returns this constant verbatim; asserting the constant pins the
        // model without reflection (nr-llm's DallEImageService is final, not mockable).
        self::assertSame('gpt-image-2', DallEImageGenerator::MODEL);
    }
}
