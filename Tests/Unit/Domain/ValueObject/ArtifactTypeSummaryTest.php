<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\ValueObject;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\ValueObject\ArtifactTypeSummary;
use PHPUnit\Framework\TestCase;

final class ArtifactTypeSummaryTest extends TestCase
{
    public function testAllDoneAggregatesToDone(): void
    {
        $summary = ArtifactTypeSummary::fromStatuses(
            ArtifactType::Schaubild,
            [ArtifactStatus::Done, ArtifactStatus::Done, ArtifactStatus::Done],
        );

        self::assertSame(ArtifactType::Schaubild, $summary->type);
        self::assertSame(ArtifactStatus::Done, $summary->status);
    }

    public function testAnyFailedAggregatesToFailedEvenWhenOthersAreDoneOrPending(): void
    {
        $summary = ArtifactTypeSummary::fromStatuses(
            ArtifactType::Schaubild,
            [ArtifactStatus::Done, ArtifactStatus::Failed, ArtifactStatus::Pending],
        );

        self::assertSame(ArtifactStatus::Failed, $summary->status);
    }

    public function testPendingWithoutFailureAggregatesToPending(): void
    {
        $summary = ArtifactTypeSummary::fromStatuses(
            ArtifactType::Podcast,
            [ArtifactStatus::Done, ArtifactStatus::Pending],
        );

        self::assertSame(ArtifactStatus::Pending, $summary->status);
    }

    public function testSingleStatusIsItsOwnAggregate(): void
    {
        self::assertSame(
            ArtifactStatus::Done,
            ArtifactTypeSummary::fromStatuses(ArtifactType::Story, [ArtifactStatus::Done])->status,
        );
        self::assertSame(
            ArtifactStatus::Pending,
            ArtifactTypeSummary::fromStatuses(ArtifactType::Story, [ArtifactStatus::Pending])->status,
        );
        self::assertSame(
            ArtifactStatus::Failed,
            ArtifactTypeSummary::fromStatuses(ArtifactType::Story, [ArtifactStatus::Failed])->status,
        );
    }

    public function testSeverityMapsAggregateStatusToContextualClass(): void
    {
        self::assertSame(
            'success',
            (new ArtifactTypeSummary(ArtifactType::Podcast, ArtifactStatus::Done))->getSeverity(),
        );
        self::assertSame(
            'danger',
            (new ArtifactTypeSummary(ArtifactType::Podcast, ArtifactStatus::Failed))->getSeverity(),
        );
        self::assertSame(
            'muted',
            (new ArtifactTypeSummary(ArtifactType::Podcast, ArtifactStatus::Pending))->getSeverity(),
        );
    }
}
