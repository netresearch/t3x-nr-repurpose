<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\Model;

use Netresearch\NrRepurpose\Domain\Model\Artifact;
use PHPUnit\Framework\TestCase;

final class ArtifactTest extends TestCase
{
    public function testGetMetadataArrayDecodesSlideMetadata(): void
    {
        $artifact = new Artifact();
        (new \ReflectionProperty(Artifact::class, 'metadata'))
            ->setValue($artifact, '{"role":"cover","slideIndex":1,"slideTotal":3}');

        self::assertSame(
            ['role' => 'cover', 'slideIndex' => 1, 'slideTotal' => 3],
            $artifact->getMetadataArray(),
        );
    }

    public function testGetMetadataArrayToleratesEmptyAndInvalidJson(): void
    {
        $artifact = new Artifact();
        self::assertSame([], $artifact->getMetadataArray());

        (new \ReflectionProperty(Artifact::class, 'metadata'))->setValue($artifact, '{broken');
        self::assertSame([], $artifact->getMetadataArray());

        (new \ReflectionProperty(Artifact::class, 'metadata'))->setValue($artifact, '"just a string"');
        self::assertSame([], $artifact->getMetadataArray());
    }
}
