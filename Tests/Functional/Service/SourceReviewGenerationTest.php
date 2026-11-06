<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Service\Decision\DecisionServiceInterface;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\FailureReason;
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\Enum\ReviewStatus;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Pipeline\PromptSnippetResolver;
use Netresearch\NrRepurpose\Provenance\AiLabelSettingsFactory;
use Netresearch\NrRepurpose\Review\ArtifactReviewService;
use Netresearch\NrRepurpose\Review\ReviewRefusedException;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolver;
use Netresearch\NrRepurpose\Service\GenerationOrchestrator;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Netresearch\NrRepurpose\SourceReview\SourceReviewGate;
use Netresearch\NrRepurpose\SourceReview\SourceReviewPointer;
use Netresearch\NrRepurpose\SourceReview\SourceReviewPolicy;
use Netresearch\NrRepurpose\SourceReview\SourceReviewProfileProvider;
use Netresearch\NrRepurpose\SourceReview\SourceReviewService;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Functional\Fixtures\SourceReviewPipelineFixture;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\SourceReviewFixture;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class SourceReviewGenerationTest extends AbstractFunctionalTestCase
{
    private function seed(): int
    {
        $connection = $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $connection->insert(
            'tx_nrrepurpose_domain_model_job',
            [
                'source_type'  => 'url',
                'source_value' => 'https://example.com/source',
                'status'       => 'queued',
            ],
        );

        return (int) $connection->lastInsertId();
    }

    private function runJob(
        int $uid,
        string $mode,
        DecisionServiceInterface $decisions,
        ArtifactGeneratorInterface $generator,
    ): void {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_repurpose']['sourceReviewMode'] = $mode;
        $jobs                                                                         = $this->get(JobProcessingRepository::class);
        $config                                                                       = $this->get(ExtensionConfiguration::class);
        $policy                                                                       = new SourceReviewPolicy($config);
        $gate                                                                         = new SourceReviewGate(
            new SourceReviewService($decisions, $policy),
            $policy,
            $jobs,
        );
        $ingestion = $this->createMock(SourceIngestionServiceInterface::class);
        $ingestion
            ->method('ingest')
            ->willReturn(
                new SourceDocument(
                    'Forecast',
                    'Sales may rise by 12%.',
                    'https://example.com/source',
                    1,
                    'en',
                ),
            );
        $analyzer = $this->createMock(DocumentAnalyzerInterface::class);
        $analyzer
            ->method('analyze')
            ->willReturn(
                new ContentBrief(
                    'Forecast',
                    'Sales will rise by 12%.',
                    ['Sales will rise.'],
                    [],
                    'Investors',
                    'en',
                ),
            );
        (new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $ingestion,
            $analyzer,
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $config,
            $this->get(CapabilityGrantResolver::class),
            $this->get(AiLabelSettingsFactory::class),
            [$generator],
            $gate,
        ))->process($uid);
    }

    public function testRejectedBriefIsPersistedBeforePipelineStops(): void
    {
        $uid       = $this->seed();
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions
            ->method('evaluate')
            ->willReturn(SourceReviewFixture::result(false));
        $generator = $this->createMock(ArtifactGeneratorInterface::class);
        $generator->expects(self::never())->method('generate');
        $this->runJob($uid, 'enforce', $decisions, $generator);
        $row = $this->get(JobProcessingRepository::class)->findRow($uid);
        self::assertNotNull($row);
        self::assertSame('failed', $row['status']);
        self::assertSame('source_review', $row['failure_reason']);
        $review = json_decode($row['source_review'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('flagged', $review['status']);
        self::assertSame('enforce', $review['mode']);
        self::assertSame(
            SourceReviewPolicy::sourcePointerHash($row),
            $review['sourcePointerHash'],
        );
    }

    public function testObservePassesTheRecordedFindingAndOriginalToGenerators(): void
    {
        $uid       = $this->seed();
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions
            ->method('evaluate')
            ->willReturn(SourceReviewFixture::result(false));
        $generator = $this->createMock(ArtifactGeneratorInterface::class);
        $generator->method('supports')->willReturn(true);
        $generator
            ->expects(self::once())
            ->method('generate')
            ->willReturnCallback(
                static function (
                    GenerationContext $ctx,
                ): bool {
                    self::assertSame('flagged', $ctx->briefReview['status']);
                    self::assertSame(
                        'Sales may rise by 12%.',
                        $ctx->document->text,
                    );
                    self::assertNotNull($ctx->sourceReview);

                    return true;
                },
            );
        $this->runJob($uid, 'observe', $decisions, $generator);
        self::assertSame(
            'done',
            $this->get(JobProcessingRepository::class)->findRow($uid)['status'],
        );
    }

    public function testRetryCannotDisableAnEnforcedFailure(): void
    {
        $uid  = $this->seed();
        $jobs = $this->get(JobProcessingRepository::class);
        $jobs->setSourceReview($uid, ['mode' => 'enforce', 'status' => 'error']);

        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions
            ->expects(self::once())
            ->method('evaluate')
            ->willReturn(SourceReviewFixture::result(false));
        $generator = $this->createMock(ArtifactGeneratorInterface::class);
        $generator->expects(self::never())->method('generate');
        $this->runJob($uid, 'off', $decisions, $generator);
        self::assertSame('failed', $jobs->findRow($uid)['status']);
        self::assertSame(
            'enforce',
            json_decode($jobs->findRow($uid)['source_review'], true)['mode'],
        );
    }

    public function testOffIsRecordedAndProfileIsAvailableFromContainer(): void
    {
        $uid       = $this->seed();
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions->expects(self::never())->method('evaluate');
        $generator = $this->createMock(ArtifactGeneratorInterface::class);
        $generator->method('supports')->willReturn(false);
        $this->runJob($uid, 'off', $decisions, $generator);
        $row = $this->get(JobProcessingRepository::class)->findRow($uid);
        self::assertSame(
            'not_run',
            json_decode($row['source_review'], true)['status'],
        );
        $profiles = $this->get(SourceReviewProfileProvider::class)->getDecisionProfiles();
        self::assertSame(SourceReviewPolicy::PROFILE, $profiles[0]->identifier);
        self::assertCount(2, $profiles[0]->questions);
        self::assertInstanceOf(
            SourceReviewGate::class,
            $this->get(SourceReviewGate::class),
        );
    }

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-repurpose',
        'typo3conf/ext/nr_repurpose/Tests/Functional/Fixtures/Extensions/nrrepurpose_source_review_fixture',
    ];

    public function testCompiledContainerInjectsGateAndRegistersProfileBeforeAnyGeneratorRuns(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_repurpose']['sourceReviewMode'] = 'enforce';
        $uid                                                                          = $this->seed();
        $this->get(GenerationOrchestratorInterface::class)->process($uid);
        $fixture = $this->get(SourceReviewPipelineFixture::class);
        self::assertSame(1, $fixture->evaluations);
        self::assertSame(0, $fixture->generations);
        $row = $this->get(JobProcessingRepository::class)->findRow($uid);
        self::assertSame('failed', $row['status']);
        $review = json_decode($row['source_review'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('flagged', $review['status']);
        self::assertSame('enforce', $review['mode']);
        self::assertSame(0, (int) $row['artifacts']);
    }

    public function testRejectedRedeliveryPreservesFinishedResultsAndRequiresANewJob(): void
    {
        $uid                                                                          = $this->seed();
        $jobs                                                                         = $this->get(JobProcessingRepository::class);
        $pool                                                                         = $this->get(ConnectionPool::class);
        $artifacts                                                                    = $pool->getConnectionForTable('tx_nrrepurpose_domain_model_artifact');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_repurpose']['sourceReviewMode'] = 'enforce';
        $policy                                                                       = new SourceReviewPolicy($this->get(ExtensionConfiguration::class));
        $document                                                                     = new SourceDocument(
            'Forecast',
            'Sales may rise by 12%.',
            'https://example.com/source',
            1,
            'en',
        );
        $text              = 'Sales may rise by 12%.';
        $acceptedDecisions = $this->createMock(DecisionServiceInterface::class);
        $acceptedDecisions
            ->expects(self::exactly(2))
            ->method('evaluate')
            ->willReturn(SourceReviewFixture::result());
        $reviewer                         = new SourceReviewService($acceptedDecisions, $policy);
        $pointer                          = new SourceReviewPointer($pool);
        $briefReview                      = $reviewer->review($text, $document, 0, 'content brief');
        $briefReview['sourcePointerHash'] = $pointer->fingerprint($jobs->findRow($uid));
        $jobs->setSourceReview($uid, $briefReview);
        $jobs->markStatus($uid, JobStatus::Generating, 'social_post', 50);

        $artifactUid = $jobs->insertArtifact(
            $uid,
            ArtifactType::SocialPost,
            'linkedin',
            0,
            ArtifactStatus::Done,
        );
        $jobs->updateArtifact(
            $artifactUid,
            [
                'script_text'   => $text,
                'source_review' => json_encode(
                    $reviewer->review($text, $document, 0, 'social post'),
                    JSON_THROW_ON_ERROR,
                ),
            ],
        );
        $approval = new ArtifactReviewService($pool, $policy, $pointer);
        $approval->review($artifactUid, ReviewStatus::Approved, 9, 1793900000);

        $preserved = $artifacts
            ->select(
                ['*'],
                'tx_nrrepurpose_domain_model_artifact',
                ['uid' => $artifactUid],
            )
            ->fetchAssociative();
        self::assertIsArray($preserved);
        self::assertSame('approved', $preserved['review_status']);
        // A killed worker has not called finishJob: the relation counter is still zero.
        self::assertSame(0, (int) $jobs->findRow($uid)['artifacts']);

        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions
            ->expects(self::once())
            ->method('evaluate')
            ->willReturn(SourceReviewFixture::result(false));
        $generator = $this->createMock(ArtifactGeneratorInterface::class);
        $generator->expects(self::never())->method('generate');
        $this->runJob($uid, 'enforce', $decisions, $generator);

        $row = $jobs->findRow($uid);
        self::assertNotNull($row);
        self::assertSame('failed', $row['status']);
        self::assertSame('source_review', $row['failure_reason']);
        $review = json_decode($row['source_review'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('enforce', $review['mode']);
        self::assertSame('flagged', $review['status']);
        self::assertSame($briefReview['sourceHash'], $review['sourceHash']);
        self::assertSame(
            $briefReview['sourcePointerHash'],
            $review['sourcePointerHash'],
        );
        self::assertSame(
            $preserved,
            $artifacts
                ->select(
                    ['*'],
                    'tx_nrrepurpose_domain_model_artifact',
                    ['uid' => $artifactUid],
                )
                ->fetchAssociative(),
        );

        // Extbase must follow the actual child relation, despite the stale zero counter.
        $loaded = $this->get(JobRepository::class)->findByUid($uid);
        self::assertInstanceOf(Job::class, $loaded);
        self::assertTrue($loaded->isFailed());
        self::assertTrue($loaded->getFailure()->isRetryable());
        self::assertSame(1, $loaded->getDoneArtifactCount());
        self::assertFalse($loaded->isRetryable());

        try {
            $approval->review(
                $artifactUid,
                ReviewStatus::Approved,
                10,
                1793900001,
            );
            self::fail(
                'The newly rejected brief must block approval of the retained result.',
            );
        } catch (ReviewRefusedException $exception) {
            self::assertSame(
                'review.refused.sourceReview',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            $preserved,
            $artifacts
                ->select(
                    ['*'],
                    'tx_nrrepurpose_domain_model_artifact',
                    ['uid' => $artifactUid],
                )
                ->fetchAssociative(),
        );
    }

    public function testOverlappingObserveRunCannotReplaceAnEnforcedBriefReview(): void
    {
        $uid         = $this->seed();
        $jobs        = $this->get(JobProcessingRepository::class);
        $newerReview = [
            'mode'          => 'enforce',
            'status'        => 'flagged',
            'candidateHash' => hash('sha256', 'newer brief'),
        ];
        $newerJson = json_encode($newerReview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $winner    = null;
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions
            ->expects(self::once())
            ->method('evaluate')
            ->willReturnCallback(
                static function () use ($jobs, $uid, $newerReview, &$winner): DecisionResult {
                    // A second worker finishes its mandatory review while this call is pending.
                    $jobs->setSourceReview($uid, $newerReview);
                    $jobs->markFailed(
                        $uid,
                        'Winning review rejected the brief.',
                        FailureReason::SourceReview,
                    );
                    $winner = $jobs->findRow($uid);

                    return SourceReviewFixture::result();
                },
            );
        $generator = $this->createMock(ArtifactGeneratorInterface::class);
        $generator->expects(self::never())->method('generate');

        $this->runJob($uid, 'observe', $decisions, $generator);

        $row = $jobs->findRow($uid);
        self::assertNotNull($row);
        self::assertSame($newerJson, $row['source_review']);
        self::assertSame('failed', $row['status']);
        self::assertSame('source_review', $row['failure_reason']);
        self::assertSame($winner, $row);
    }

    /** @return iterable<string, array{JobStatus}> */
    public static function completedWinningStatuses(): iterable
    {
        yield 'completed' => [JobStatus::Done];
        yield 'partially completed' => [JobStatus::PartiallyDone];
    }

    #[DataProvider('completedWinningStatuses')]
    public function testSupersededBriefReviewPreservesTheWinningJobAndArtifacts(
        JobStatus $winningStatus,
    ): void {
        $uid       = $this->seed();
        $jobs      = $this->get(JobProcessingRepository::class);
        $artifacts = $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact');
        $newerReview = [
            'mode'          => 'enforce',
            'status'        => 'passed',
            'candidateHash' => hash('sha256', 'Winning brief'),
            'model'         => 'winning reviewer',
        ];
        $winner           = null;
        $winningArtifacts = [];
        $decisions        = $this->createMock(DecisionServiceInterface::class);
        $decisions
            ->expects(self::once())
            ->method('evaluate')
            ->willReturnCallback(
                static function () use ($jobs, $uid, $newerReview, $winningStatus, $artifacts, &$winner, &$winningArtifacts): DecisionResult {
                    // The other worker completes while this worker's external review is pending.
                    $jobs->setSourceReview($uid, $newerReview);
                    $artifactUid = $jobs->insertArtifact(
                        $uid,
                        ArtifactType::SocialPost,
                        'linkedin',
                        0,
                        ArtifactStatus::Done,
                    );
                    $jobs->updateArtifact(
                        $artifactUid,
                        [
                            'script_text'   => 'Winning final copy',
                            'source_review' => json_encode(
                                $newerReview + ['scope' => 'winning artifact'],
                                JSON_THROW_ON_ERROR,
                            ),
                        ],
                    );
                    $artifacts->update(
                        'tx_nrrepurpose_domain_model_artifact',
                        [
                            'review_status' => ReviewStatus::Approved->value,
                            'reviewed_by'   => 9,
                            'reviewed_at'   => 1794000000,
                        ],
                        ['uid' => $artifactUid],
                    );
                    if ($winningStatus === JobStatus::PartiallyDone) {
                        $jobs->insertArtifact(
                            $uid,
                            ArtifactType::Handout,
                            'default',
                            0,
                            ArtifactStatus::Failed,
                        );
                    }

                    $jobs->finishJob($uid, $winningStatus, '');
                    $winner           = $jobs->findRow($uid);
                    $winningArtifacts = $artifacts->select(
                        ['*'],
                        'tx_nrrepurpose_domain_model_artifact',
                        ['job' => $uid],
                        orderBy: ['uid' => 'ASC'],
                    )->fetchAllAssociative();

                    return SourceReviewFixture::result();
                },
            );
        $generator = $this->createMock(ArtifactGeneratorInterface::class);
        $generator->expects(self::never())->method('generate');

        $this->runJob($uid, 'observe', $decisions, $generator);

        self::assertIsArray($winner);
        self::assertSame($winningStatus->value, $winner['status']);
        self::assertSame($winner, $jobs->findRow($uid));
        self::assertNotEmpty($winningArtifacts);
        self::assertSame(
            $winningArtifacts,
            $artifacts
                ->select(
                    ['*'],
                    'tx_nrrepurpose_domain_model_artifact',
                    ['job' => $uid],
                    orderBy: ['uid' => 'ASC'],
                )
                ->fetchAllAssociative(),
        );
    }
}
