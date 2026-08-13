<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\Model;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Model\Artifact;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\ValueObject\ArtifactTypeSummary;
use Netresearch\NrRepurpose\Domain\ValueObject\PromptSnippetSelection;
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
        $job->getArtifacts()->attach($this->artifact(ArtifactType::Story, ArtifactStatus::Failed));
        $job->getArtifacts()->attach($this->artifact(ArtifactType::Podcast, ArtifactStatus::Done));
        $job->getArtifacts()->attach($this->artifact(ArtifactType::Schaubild, ArtifactStatus::Pending));

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
        $job->getArtifacts()->attach($this->artifact(ArtifactType::Schaubild, ArtifactStatus::Done));
        $job->getArtifacts()->attach($this->artifact(ArtifactType::Schaubild, ArtifactStatus::Failed));
        $job->getArtifacts()->attach($this->artifact(ArtifactType::Schaubild, ArtifactStatus::Done));

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

    public function testPromptSnippetSelectionIsEmptyByDefault(): void
    {
        $job = new Job();

        self::assertSame('', $job->getPromptSnippets());
        self::assertTrue($job->getPromptSnippetSelection()->isEmpty());
    }

    public function testPromptSnippetSelectionRoundTripsThroughTheJsonColumn(): void
    {
        $job = new Job();
        $job->setPromptSnippetSelection(new PromptSnippetSelection(audience: 5, tone: 6, personas: [7, 8], storyStyle: 9));

        // Persisted as the agreed JSON shape ...
        self::assertSame(
            ['audience' => 5, 'tone' => 6, 'personas' => [7, 8], 'schaubild' => ['layout' => 0, 'style' => 0], 'story' => ['layout' => 0, 'style' => 9]],
            json_decode($job->getPromptSnippets(), true),
        );

        // ... and read back as an equal value object.
        $restored = $job->getPromptSnippetSelection();
        self::assertSame(5, $restored->audience);
        self::assertSame([7, 8], $restored->personas);
        self::assertSame(9, $restored->storyStyle);
    }

    private function artifact(ArtifactType $type, ArtifactStatus $status): Artifact
    {
        return new class ($type, $status) extends Artifact {
            public function __construct(ArtifactType $type, ArtifactStatus $status)
            {
                $this->type   = $type->value;
                $this->status = $status->value;
            }
        };
    }

    /** Raw-string variant with explicit uid + optional slideIndex metadata — getStoryArtifacts() sorts by slideIndex, then uid. */
    private function storyArtifact(string $type, string $variant, int $uid, ?int $slideIndex = null): Artifact
    {
        $artifact = new Artifact();
        $artifact->_setProperty('type', $type);
        $artifact->_setProperty('variant', $variant);
        $artifact->_setProperty('uid', $uid);
        if ($slideIndex !== null) {
            $artifact->_setProperty('metadata', json_encode(['slideIndex' => $slideIndex], JSON_THROW_ON_ERROR));
        }

        return $artifact;
    }
}
