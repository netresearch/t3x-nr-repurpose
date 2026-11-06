<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\SourceReview;

use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Service\Decision\DecisionRequest;
use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Service\Decision\DecisionServiceInterface;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\SourceReview\SourceReviewPolicy;
use Netresearch\NrRepurpose\SourceReview\SourceReviewService;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\SourceReviewFixture;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class SourceReviewServiceTest extends TestCase
{
    /** @param array<string, mixed> $values */
    private function policy(array $values = []): SourceReviewPolicy
    {
        $configuration = $this->createMock(ExtensionConfiguration::class);
        $configuration
            ->method('get')
            ->willReturnCallback(
                static fn (
                    string $extension,
                    string $path,
                ): mixed => $values[$path] ?? match ($path) {
                    'sourceReviewMode'         => 'enforce',
                    'sourceReviewMinimumScore' => '0.8',
                    'sourceReviewDataClass'    => 'editorContent',
                    default                    => '',
                },
            );

        return new SourceReviewPolicy($configuration);
    }

    private function document(
        string $text = 'Sales may rise by 12 percent in 2027.',
    ): SourceDocument {
        return new SourceDocument('Forecast', $text, 'source', 1, 'en');
    }

    public function testOriginalTitleAndEntireEvidenceReachIndependentReviewerWithAttribution(): void
    {
        $originalText     = str_repeat('Original source passage. ', 12000) . 'Final original qualifier.';
        $document         = $this->document($originalText);
        $expectedEvidence = "Source title: Forecast\nSource attribution: source\n\nOriginal text:\n" . $originalText;
        $decisions        = $this->createMock(DecisionServiceInterface::class);
        $decisions
            ->expects(self::once())
            ->method('assertAvailable')
            ->with(SourceReviewPolicy::PROFILE, 'separate-reviewer');
        $decisions
            ->expects(self::once())
            ->method('evaluate')
            ->willReturnCallback(
                static function (
                    DecisionRequest $request,
                ) use ($expectedEvidence): DecisionResult {
                    self::assertSame(
                        'Final shortened copy',
                        $request->subject->candidate,
                    );
                    self::assertSame(
                        [$expectedEvidence],
                        $request->subject->evidence,
                    );
                    self::assertSame(
                        'separate-reviewer',
                        $request->configuration,
                    );
                    self::assertSame(42, $request->beUserUid);
                    self::assertSame(
                        'nr_repurpose',
                        $request->callerSourceExtension,
                    );
                    self::assertSame(
                        'source_review',
                        $request->callerSourceOperation,
                    );

                    return SourceReviewFixture::result();
                },
            );
        $result = (new SourceReviewService(
            $decisions,
            $this->policy(['sourceReviewConfiguration' => 'separate-reviewer']),
        ))->review('Final shortened copy', $document, 42, 'final text');
        self::assertSame('passed', $result['status']);
        self::assertSame('independent-reviewer', $result['model']);
        self::assertSame(
            hash('sha256', $expectedEvidence),
            $result['sourceHash'],
        );
        self::assertSame(
            hash('sha256', 'Final shortened copy'),
            $result['candidateHash'],
        );
        self::assertSame('none', $result['probabilityKind']);
    }

    public function testDisabledIsVisibleAndNeverCallsReviewer(): void
    {
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions->expects(self::never())->method('assertAvailable');
        $decisions->expects(self::never())->method('evaluate');
        $result = (new SourceReviewService(
            $decisions,
            $this->policy(['sourceReviewMode' => 'off']),
        ))->review('copy', $this->document(), 1, 'final text');
        self::assertSame('not_run', $result['status']);
        self::assertSame('disabled', $result['reason']);
    }

    public function testMissingEvidenceIsAnErrorWithoutPaidCall(): void
    {
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions->expects(self::never())->method('evaluate');
        $result = (new SourceReviewService($decisions, $this->policy()))->review(
            'copy',
            $this->document(' '),
            1,
            'final text',
        );
        self::assertSame('error', $result['status']);
        self::assertSame('no_evidence', $result['reason']);
    }

    public function testProviderFailureIsErrorAndDoesNotLeakMessage(): void
    {
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions
            ->method('evaluate')
            ->willThrowException(new RuntimeException('SECRET provider URL and source text'));
        $result = (new SourceReviewService(
            $decisions,
            $this->policy(['sourceReviewMode' => 'observe']),
        ))->review('copy', $this->document(), 1, 'final text');
        self::assertSame('error', $result['status']);
        self::assertSame('review_unavailable', $result['reason']);
        self::assertStringNotContainsString(
            'SECRET',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    public function testMissingConfigurationIsErrorEvenInObserveMode(): void
    {
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions
            ->method('assertAvailable')
            ->willThrowException(new RuntimeException('no config'));
        $decisions->expects(self::never())->method('evaluate');
        $result = (new SourceReviewService(
            $decisions,
            $this->policy(['sourceReviewMode' => 'observe']),
        ))->review('copy', $this->document(), 1, 'final text');
        self::assertSame('error', $result['status']);
    }

    public function testFlagAndUncertainYesDoNotPassThreshold(): void
    {
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions
            ->method('evaluate')
            ->willReturn(
                new DecisionResult(
                    SourceReviewPolicy::PROFILE,
                    1,
                    'reviewer',
                    'test',
                    'model',
                    ProbabilityKind::Distribution,
                    [
                        'supported'      => DecisionAnswer::yesNo('supported', 0.79),
                        'qualifications' => DecisionAnswer::yesNo('qualifications', 1.0),
                    ],
                ),
            );
        $result = (new SourceReviewService($decisions, $this->policy()))->review(
            'Sales will rise.',
            $this->document(),
            1,
            'final text',
        );
        self::assertSame('flagged', $result['status']);
        self::assertSame(0.79, $result['answers']['supported']);
        self::assertSame('distribution', $result['probabilityKind']);
    }

    public function testUnexpectedProfileAndMissingAnswersAreErrors(): void
    {
        foreach ([
            new DecisionResult(
                'wrong',
                1,
                '',
                '',
                '',
                ProbabilityKind::None,
                [],
            ),
            new DecisionResult(
                SourceReviewPolicy::PROFILE,
                1,
                '',
                '',
                '',
                ProbabilityKind::None,
                [],
            ),
        ] as $answer) {
            $decisions = $this->createMock(DecisionServiceInterface::class);
            $decisions->method('evaluate')->willReturn($answer);
            $result = (new SourceReviewService($decisions, $this->policy()))->review(
                'copy',
                $this->document(),
                1,
                'final text',
            );
            self::assertSame('error', $result['status']);
        }
    }

    public function testStoredEnforceCannotBeDisabledByRetry(): void
    {
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions
            ->expects(self::once())
            ->method('evaluate')
            ->willReturn(SourceReviewFixture::result(false));
        $result = (new SourceReviewService(
            $decisions,
            $this->policy(['sourceReviewMode' => 'off']),
        ))->review('copy', $this->document(), 1, 'final text', true);
        self::assertSame('enforce', $result['mode']);
        self::assertSame('flagged', $result['status']);
    }

    public function testPolicyChangeInvalidatesOldAssessment(): void
    {
        $review = [
            'status'                => 'passed',
            'mode'                  => 'enforce',
            'profile'               => SourceReviewPolicy::PROFILE,
            'profileVersion'        => 1,
            'sourceHash'            => str_repeat('a', 64),
            'candidateHash'         => hash('sha256', 'copy'),
            'answers'               => ['supported' => 0.85, 'qualifications' => 1.0],
            'dataClass'             => 'editorContent',
            'configurationSelector' => null,
        ];
        self::assertTrue(
            $this->policy()->allowsArtifact($review, 'copy', $review),
        );
        self::assertFalse(
            $this
                ->policy(['sourceReviewMinimumScore' => '0.95'])
                ->allowsArtifact($review, 'copy', $review),
        );
        self::assertFalse(
            $this
                ->policy(['sourceReviewDataClass' => 'secretAdjacent'])
                ->allowsArtifact($review, 'copy', $review),
        );
        self::assertFalse(
            $this
                ->policy(['decision/configuration' => 'new-reviewer'])
                ->allowsArtifact($review, 'copy', $review),
        );
        self::assertFalse(
            $this->policy()->allowsArtifact($review, 'edited copy', $review),
        );
        self::assertFalse(
            $this
                ->policy()
                ->allowsArtifact(
                    $review,
                    'copy',
                    array_replace(
                        $review,
                        ['sourceHash' => str_repeat('b', 64)],
                    ),
                ),
        );
    }

    public function testLegacyMissingSettingIsOffButUnexpectedConfigurationFailureIsNot(): void
    {
        $missing = $this->createMock(ExtensionConfiguration::class);
        $missing
            ->method('get')
            ->willThrowException(new ExtensionConfigurationPathDoesNotExistException());
        self::assertSame('off', (new SourceReviewPolicy($missing))->mode());
        $broken = $this->createMock(ExtensionConfiguration::class);
        $broken
            ->method('get')
            ->willThrowException(new RuntimeException('broken reader'));
        $policy = new SourceReviewPolicy($broken);
        self::assertSame('enforce', $policy->mode());
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions->expects(self::never())->method('evaluate');
        self::assertSame(
            'error',
            (new SourceReviewService($decisions, $policy))->review(
                'copy',
                $this->document(),
                1,
                'text',
            )['status'],
        );
    }

    public function testInvalidExplicitConfigurationDoesNotSelectTheGlobalReviewer(): void
    {
        $policy = $this->policy(
            [
                'sourceReviewConfiguration' => false,
                'decision/configuration'    => 'global-reviewer',
            ],
        );
        self::assertSame(
            SourceReviewPolicy::UNAVAILABLE_CONFIGURATION,
            $policy->configuration(),
        );
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions->expects(self::never())->method('evaluate');
        self::assertSame(
            'error',
            (new SourceReviewService($decisions, $policy))->review(
                'copy',
                $this->document(),
                1,
                'text',
            )['status'],
        );
    }
}
