<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\Enum;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use PHPUnit\Framework\TestCase;

final class ArtifactStatusTest extends TestCase
{
    public function testLabelKeyIsNamespacedByBackedValue(): void
    {
        self::assertSame('artifactStatus.pending', ArtifactStatus::Pending->getLabelKey());
        self::assertSame('artifactStatus.done', ArtifactStatus::Done->getLabelKey());
        self::assertSame('artifactStatus.failed', ArtifactStatus::Failed->getLabelKey());
    }

    public function testSeverityMapsEachStatusToAContextualClass(): void
    {
        self::assertSame('success', ArtifactStatus::Done->getSeverity());
        self::assertSame('danger', ArtifactStatus::Failed->getSeverity());
        self::assertSame('warning', ArtifactStatus::Pending->getSeverity());
    }
}
