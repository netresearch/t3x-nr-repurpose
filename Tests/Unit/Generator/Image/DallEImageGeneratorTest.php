<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Image;

use Netresearch\NrRepurpose\Generator\Image\DallEImageGenerator;
use PHPUnit\Framework\TestCase;

final class DallEImageGeneratorTest extends TestCase
{
    public function testGetModelReportsTheGptImage2Model(): void
    {
        // getModel() only reads the class constant; bypass the constructor so the test
        // does not need a (final) nr-llm DallEImageService instance.
        $generator = (new \ReflectionClass(DallEImageGenerator::class))->newInstanceWithoutConstructor();

        self::assertSame('gpt-image-2', $generator->getModel());
    }
}
