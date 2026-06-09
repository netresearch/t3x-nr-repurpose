<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\Enum;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use PHPUnit\Framework\TestCase;

final class ArtifactStatusTest extends TestCase
{
    public function testLabelKeyIsNamespacedByBackedValue(): void
    {
        self::assertSame('artifactStatus.pending', ArtifactStatus::Pending->labelKey());
        self::assertSame('artifactStatus.done', ArtifactStatus::Done->labelKey());
        self::assertSame('artifactStatus.failed', ArtifactStatus::Failed->labelKey());
    }

    public function testSeverityMapsEachStatusToAContextualClass(): void
    {
        self::assertSame('success', ArtifactStatus::Done->severity());
        self::assertSame('danger', ArtifactStatus::Failed->severity());
        self::assertSame('warning', ArtifactStatus::Pending->severity());
    }
}
