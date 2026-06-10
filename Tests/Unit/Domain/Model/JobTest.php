<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\Model;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Model\Artifact;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\ValueObject\ArtifactTypeSummary;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Extbase\DomainObject\AbstractDomainObject;

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

    public function testGetStoryArtifactsReturnsOnlyStorySlidesInSlideIndexOrder(): void
    {
        $job = new Job();
        $job->getArtifacts()->attach($this->storyArtifact('schaubild', 'html', 1));
        // uid order (11, 12) contradicts slide order: the slideIndex metadata must win.
        $job->getArtifacts()->attach($this->storyArtifact('story', 'slide-2', 11, 2));
        $job->getArtifacts()->attach($this->storyArtifact('story', 'slide-1', 12, 1));
        $job->getArtifacts()->attach($this->storyArtifact('podcast', 'default', 2));

        $slides = $job->getStoryArtifacts();

        self::assertCount(2, $slides);
        self::assertSame(['slide-1', 'slide-2'], array_map(
            static fn (Artifact $a): string => $a->getVariant(),
            $slides,
        ));
    }

    public function testGetStoryArtifactsFallsBackToUidOrderWithoutSlideIndexMetadata(): void
    {
        $job = new Job();
        $job->getArtifacts()->attach($this->storyArtifact('story', 'late', 12));
        $job->getArtifacts()->attach($this->storyArtifact('story', 'early', 11));

        self::assertSame(['early', 'late'], array_map(
            static fn (Artifact $a): string => $a->getVariant(),
            $job->getStoryArtifacts(),
        ));
    }

    public function testGetStoryArtifactsIsEmptyWithoutStorySlides(): void
    {
        $job = new Job();
        $job->getArtifacts()->attach($this->storyArtifact('podcast', 'default', 1));

        self::assertSame([], $job->getStoryArtifacts());
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

    /** Raw-string variant with explicit uid + optional slideIndex metadata — getStoryArtifacts() sorts by slideIndex, then uid. */
    private function storyArtifact(string $type, string $variant, int $uid, ?int $slideIndex = null): Artifact
    {
        $artifact = new Artifact();
        (new \ReflectionProperty(Artifact::class, 'type'))->setValue($artifact, $type);
        (new \ReflectionProperty(Artifact::class, 'variant'))->setValue($artifact, $variant);
        (new \ReflectionProperty(AbstractDomainObject::class, 'uid'))->setValue($artifact, $uid);
        if ($slideIndex !== null) {
            (new \ReflectionProperty(Artifact::class, 'metadata'))
                ->setValue($artifact, json_encode(['slideIndex' => $slideIndex], JSON_THROW_ON_ERROR));
        }

        return $artifact;
    }
}
