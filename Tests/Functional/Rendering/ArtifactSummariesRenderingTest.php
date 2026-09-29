<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\ValueObject\ArtifactTypeSummary;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * The artifact column of the job list (Partials/Job/ArtifactSummaries.html), rendered
 * through the real core icon ViewHelper: the status of each artifact type is carried by
 * a core icon overlay and the accessible name, not by the text colour alone.
 */
final class ArtifactSummariesRenderingTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    /** @return array<string, array{0: ArtifactStatus, 1: string|null}> */
    public static function statuses(): array
    {
        return [
            'done'    => [ArtifactStatus::Done, null],
            'failed'  => [ArtifactStatus::Failed, 'overlay-missing'],
            'pending' => [ArtifactStatus::Pending, 'overlay-scheduled'],
        ];
    }

    #[DataProvider('statuses')]
    public function testTheStatusIsAnOverlayNotOnlyAColour(ArtifactStatus $status, ?string $overlay): void
    {
        $html = $this->render([new ArtifactTypeSummary(ArtifactType::SlideDeck, $status)]);

        self::assertStringContainsString('data-identifier="' . ArtifactType::SlideDeck->getIconIdentifier() . '"', $html);
        if ($overlay === null) {
            self::assertStringNotContainsString('icon-overlay', $html);
        } else {
            self::assertMatchesRegularExpression('/class="[^"]*\bicon-overlay\b[^"]*\bicon-' . $overlay . '\b/', $html);
        }
    }

    public function testEveryIconHasTheTypeAndStatusAsItsName(): void
    {
        $html = $this->render([
            new ArtifactTypeSummary(ArtifactType::Podcast, ArtifactStatus::Done),
            new ArtifactTypeSummary(ArtifactType::Handout, ArtifactStatus::Failed),
        ]);

        self::assertSame(2, substr_count($html, 'role="img"'));
        self::assertMatchesRegularExpression('/role="img" title="([^"]+)" aria-label="\1"/', $html);
    }

    public function testNoSummaryRendersADash(): void
    {
        self::assertSame('—', trim(strip_tags($this->render([]))));
    }

    /** @param list<ArtifactTypeSummary> $summaries */
    private function render(array $summaries): string
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templatePathAndFilename: GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Resources/Private/Partials/Job/ArtifactSummaries.html'),
        ));
        $view->assign('summaries', $summaries);

        return $view->render();
    }
}
