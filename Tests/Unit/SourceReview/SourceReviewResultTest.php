<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\SourceReview;

use Netresearch\NrRepurpose\Domain\Model\Artifact;
use Netresearch\NrRepurpose\SourceReview\SourceReviewResult;
use PHPUnit\Framework\TestCase;

final class SourceReviewResultTest extends TestCase
{
    public function testLegacyAndMalformedResultsNeverAppearPassed(): void
    {
        foreach (['', '{broken', 'null', '"passed"', '{"status":"other"}'] as $json) {
            self::assertSame(
                'not_run',
                SourceReviewResult::fromJson($json)->getStatus(),
            );
        }
    }

    public function testChangedCopyNoLongerDisplaysAnAcceptedAssessment(): void
    {
        $artifact = new Artifact();
        $artifact->_setProperty(
            'sourceReview',
            json_encode(
                [
                    'status'        => 'passed',
                    'candidateHash' => hash('sha256', 'original text'),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        $artifact->_setProperty('scriptText', 'changed text');
        self::assertSame('error', $artifact->getSourceReviewResult()->getStatus());
        self::assertTrue($artifact->getSourceReviewResult()->getCandidateChanged());
    }

    public function testTimestampAndModelAreAvailableForTheReviewDetails(): void
    {
        $review = SourceReviewResult::fromJson(
            '{"status":"passed","evaluatedAt":1793900000,"model":"judge-model","mode":"enforce"}',
        );
        self::assertSame(1793900000, $review->getEvaluatedAt());
        self::assertSame('judge-model', $review->getModel());
        self::assertSame('success', $review->getSeverity());
    }
}
