<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\Enum;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use PHPUnit\Framework\TestCase;

final class ArtifactTypeTest extends TestCase
{
    public function testLabelKeyIsNamespacedByBackedValue(): void
    {
        self::assertSame('artifact.podcast', ArtifactType::Podcast->getLabelKey());
        self::assertSame('artifact.schaubild', ArtifactType::Schaubild->getLabelKey());
        self::assertSame('artifact.story', ArtifactType::Story->getLabelKey());
        self::assertSame('artifact.stub', ArtifactType::Stub->getLabelKey());
    }

    public function testIconIdentifierMapsEachTypeToACoreIcon(): void
    {
        self::assertSame('mimetypes-media-audio', ArtifactType::Podcast->getIconIdentifier());
        self::assertSame('content-widget-chart', ArtifactType::Schaubild->getIconIdentifier());
        self::assertSame('actions-device-mobile', ArtifactType::Story->getIconIdentifier());
        self::assertSame('miscellaneous-placeholder', ArtifactType::Stub->getIconIdentifier());
    }
}
