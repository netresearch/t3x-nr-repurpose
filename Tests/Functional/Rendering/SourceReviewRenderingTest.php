<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

use Netresearch\NrRepurpose\SourceReview\SourceReviewResult;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

final class SourceReviewRenderingTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    /** @return array<string, array{string, string}> */
    public static function statuses(): array
    {
        return [
            'passed'  => ['passed', 'No issues detected automatically'],
            'flagged' => ['flagged', 'Issues detected'],
            'error'   => ['error', 'Review unavailable or outdated'],
            'not run' => ['not_run', 'Not reviewed'],
        ];
    }

    #[DataProvider('statuses')]
    public function testTheRealPartialShowsTheMachineStatusSeparatelyFromApproval(
        string $status,
        string $label,
    ): void {
        $html = $this->render(
            SourceReviewResult::fromJson(
                json_encode(['status' => $status], JSON_THROW_ON_ERROR),
            ),
        );
        self::assertStringContainsString($label, $html);
        self::assertStringContainsString(
            'separate from editorial approval',
            $html,
        );
        self::assertStringContainsString('data-status="' . $status . '"', $html);
        self::assertStringNotContainsString('{review.', $html);
    }

    public function testLegacyRowsShowNotReviewedAndNeverAFalseSuccess(): void
    {
        $html = $this->render(SourceReviewResult::fromJson(''));
        self::assertStringContainsString('Not reviewed', $html);
        self::assertStringNotContainsString(
            'No issues detected automatically',
            $html,
        );
    }

    public function testDetailsEscapeTheModelNameAndShowTheTimestamp(): void
    {
        $html = $this->render(
            SourceReviewResult::fromJson(
                '{"status":"passed","evaluatedAt":1793900000,"model":"<script>judge</script>","mode":"observe"}',
            ),
        );
        self::assertStringContainsString(
            '&lt;script&gt;judge&lt;/script&gt;',
            $html,
        );
        self::assertStringNotContainsString('<script>judge</script>', $html);
        self::assertStringContainsString('Reviewed at', $html);
        self::assertStringContainsString('Observation mode', $html);
    }

    private function render(SourceReviewResult $review): string
    {
        $view = $this
            ->get(ViewFactoryInterface::class)
            ->create(
                new ViewFactoryData(
                    templatePathAndFilename: GeneralUtility::getFileAbsFileName(
                        'EXT:nr_repurpose/Resources/Private/Partials/Job/SourceReview.html',
                    ),
                ),
            );
        $view->assign('review', $review);

        return $view->render();
    }

    public function testUnavailableReviewShowsAUsefulFixedReasonWithoutProviderDetails(): void
    {
        $html = $this->render(
            SourceReviewResult::fromJson(
                '{"status":"error","reason":"review_unavailable"}',
            ),
        );
        self::assertStringContainsString(
            'Check the reviewer configuration and availability',
            $html,
        );
        $untrusted = $this->render(
            SourceReviewResult::fromJson(
                '{"status":"error","reason":"private provider secret"}',
            ),
        );
        self::assertStringNotContainsString(
            'private provider secret',
            $untrusted,
        );
        self::assertStringContainsString(
            'Check the reviewer configuration and availability',
            $untrusted,
        );
    }

    public function testAFlaggedReviewExplainsTheSourceMismatch(): void
    {
        $html = $this->render(
            SourceReviewResult::fromJson(
                '{"status":"flagged","reason":"source_mismatch"}',
            ),
        );
        self::assertStringContainsString(
            'Compare the text with the original source',
            $html,
        );
    }
}
