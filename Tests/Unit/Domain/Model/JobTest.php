<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\Model;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Model\Artifact;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\ValueObject\ArtifactTypeSummary;
use PHPUnit\Framework\TestCase;

final class JobTest extends TestCase
{
    public function testArtifactTypeSummariesAreEmptyForAJobWithoutArtifacts(): void
    {
        self::assertSame([], (new Job())->getArtifactTypeSummaries());
    }

    public function testArtifactTypeSummariesGroupArtifactsByTypeInEnumOrder(): void
    {
        $job = new Job();
        $job->getArtifacts()->attach(self::artifact(ArtifactType::Story, ArtifactStatus::Failed));
        $job->getArtifacts()->attach(self::artifact(ArtifactType::Podcast, ArtifactStatus::Done));
        $job->getArtifacts()->attach(self::artifact(ArtifactType::Schaubild, ArtifactStatus::Pending));

        $summaries = $job->getArtifactTypeSummaries();

        self::assertSame(
            [ArtifactType::Podcast, ArtifactType::Schaubild, ArtifactType::Story],
            array_map(static fn (ArtifactTypeSummary $summary): ArtifactType => $summary->type, $summaries),
        );
        self::assertSame(
            [ArtifactStatus::Done, ArtifactStatus::Pending, ArtifactStatus::Failed],
            array_map(static fn (ArtifactTypeSummary $summary): ArtifactStatus => $summary->status, $summaries),
        );
    }

    public function testArtifactTypeSummariesAggregateAllVariantsOfOneType(): void
    {
        $job = new Job();
        $job->getArtifacts()->attach(self::artifact(ArtifactType::Schaubild, ArtifactStatus::Done));
        $job->getArtifacts()->attach(self::artifact(ArtifactType::Schaubild, ArtifactStatus::Failed));
        $job->getArtifacts()->attach(self::artifact(ArtifactType::Schaubild, ArtifactStatus::Done));

        $summaries = $job->getArtifactTypeSummaries();

        self::assertCount(1, $summaries);
        self::assertSame(ArtifactType::Schaubild, $summaries[0]->type);
        self::assertSame(ArtifactStatus::Failed, $summaries[0]->status);
    }

    private static function artifact(ArtifactType $type, ArtifactStatus $status): Artifact
    {
        return new class($type, $status) extends Artifact {
            public function __construct(ArtifactType $type, ArtifactStatus $status)
            {
                $this->type = $type->value;
                $this->status = $status->value;
            }
        };
    }
}
