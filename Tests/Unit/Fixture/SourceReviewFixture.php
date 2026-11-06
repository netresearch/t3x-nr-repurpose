<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Service\Decision\DecisionServiceInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\SourceReview\SourceReviewGate;
use Netresearch\NrRepurpose\SourceReview\SourceReviewPolicy;
use Netresearch\NrRepurpose\SourceReview\SourceReviewService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class SourceReviewFixture
{
    public static function attach(
        GenerationContext $ctx,
        JobProcessingRepository $jobs,
        DecisionServiceInterface $decisions,
        string $mode = 'enforce',
    ): GenerationContext {
        $configuration = new readonly class ($mode) extends ExtensionConfiguration {
            public function __construct(private string $mode) {}

            public function get(string $extension, string $path = ''): mixed
            {
                return match ($path) {
                    'sourceReviewMode'         => $this->mode,
                    'sourceReviewDataClass'    => 'editorContent',
                    'sourceReviewMinimumScore' => '0.8',
                    default                    => '',
                };
            }
        };
        $policy      = new SourceReviewPolicy($configuration);
        $briefReview = [
            'mode'                  => $mode,
            'status'                => 'passed',
            'profile'               => SourceReviewPolicy::PROFILE,
            'profileVersion'        => SourceReviewPolicy::VERSION,
            'dataClass'             => 'editorContent',
            'configuration'         => 'reviewer',
            'configurationSelector' => null,
            'sourceHash'            => hash('sha256', SourceReviewService::sourceEvidence($ctx->document)),
            'answers'               => ['supported' => 1.0, 'qualifications' => 1.0],
        ];

        return new GenerationContext(
            $ctx->job,
            $ctx->document,
            $ctx->brief,
            $ctx->theme,
            $ctx->beUser,
            $ctx->snippets,
            $ctx->progress,
            $ctx->grants,
            $ctx->aiLabel,
            new SourceReviewGate(
                new SourceReviewService($decisions, $policy),
                $policy,
                $jobs,
            ),
            $briefReview,
        );
    }

    public static function result(bool $passed = true): DecisionResult
    {
        return new DecisionResult(
            SourceReviewPolicy::PROFILE,
            SourceReviewPolicy::VERSION,
            'reviewer',
            'test',
            'independent-reviewer',
            ProbabilityKind::None,
            [
                'supported'      => DecisionAnswer::yesNo('supported', $passed ? 1.0 : 0.0),
                'qualifications' => DecisionAnswer::yesNo('qualifications', 1.0),
            ],
        );
    }
}
