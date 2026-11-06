<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Review;

use Netresearch\NrRepurpose\Domain\Enum\ReviewStatus;
use Netresearch\NrRepurpose\Review\ArtifactReviewService;
use Netresearch\NrRepurpose\Review\ReviewRefusedException;
use Netresearch\NrRepurpose\Social\DuePostPublisher;
use Netresearch\NrRepurpose\Social\SocialPost;
use Netresearch\NrRepurpose\Social\SocialPublisherInterface;
use Netresearch\NrRepurpose\SourceReview\SourceReviewPointer;
use Netresearch\NrRepurpose\SourceReview\SourceReviewPolicy;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SourceReviewPublishingTest extends AbstractFunctionalTestCase
{
    private const string ARTIFACT = 'tx_nrrepurpose_domain_model_artifact';

    private const string JOB = 'tx_nrrepurpose_domain_model_job';

    private const string TEXT = 'No registration deadline has been set.';

    private const int NOW = 1793900000;

    /** @return array<string, array{string}> */
    public static function unacceptedReviews(): array
    {
        return [
            'flagged' => ['flagged'],
            'error'   => ['error'],
            'not run' => ['not_run'],
        ];
    }

    #[DataProvider('unacceptedReviews')]
    public function testEnforcementPreventsHumanApprovalFromOverridingTheSourceReview(
        string $status,
    ): void {
        [$uid]   = $this->seed($this->reviewRecord($status));
        $service = new ArtifactReviewService(
            $this->pool(),
            $this->policy('enforce'),
            new SourceReviewPointer($this->pool()),
        );

        $this->expectException(ReviewRefusedException::class);
        $this->expectExceptionMessage('review.refused.sourceReview');
        $service->review($uid, ReviewStatus::Approved, 9, self::NOW);
    }

    public function testRejectionRemainsAvailableWhenTheSourceReviewBlocksApproval(): void
    {
        [$uid] = $this->seed($this->reviewRecord('flagged'));
        (new ArtifactReviewService(
            $this->pool(),
            $this->policy('enforce'),
            new SourceReviewPointer($this->pool()),
        ))->review(
            $uid,
            ReviewStatus::Rejected,
            9,
            self::NOW,
        );

        self::assertSame('rejected', $this->artifact($uid)['review_status']);
    }

    public function testSchedulingRefusesAChangedTextEvenAfterHumanApproval(): void
    {
        [$uid] = $this->seed($this->reviewRecord('passed'));
        $this
            ->pool()
            ->getConnectionForTable(self::ARTIFACT)
            ->update(
                self::ARTIFACT,
                ['script_text' => 'A new deadline is tomorrow.'],
                ['uid' => $uid],
            );
        $this->expectException(ReviewRefusedException::class);
        $this->expectExceptionMessage('review.refused.sourceReview');

        (new ArtifactReviewService(
            $this->pool(),
            $this->policy('enforce'),
            new SourceReviewPointer($this->pool()),
        ))->schedule(
            $uid,
            self::NOW,
        );
    }

    #[DataProvider('unacceptedReviews')]
    public function testPublicationRechecksTheReviewAndNeverSendsAnUnacceptedPost(
        string $status,
    ): void {
        [$uid]   = $this->seed($this->reviewRecord($status));
        $channel = $this->channel();
        $report  = (new DuePostPublisher(
            $this->pool(),
            $channel,
            $this->policy('enforce'),
            new SourceReviewPointer($this->pool()),
        ))->publishDue(
            self::NOW,
        );

        self::assertSame([], $channel->sent);
        self::assertSame(1, $report->failed);
        self::assertSame('failed', $this->artifact($uid)['publish_status']);
        self::assertStringContainsString(
            'source review',
            $this->artifact($uid)['publish_error'],
        );
    }

    public function testPublicationChecksTheCurrentBriefingReviewAgainstTheArtifactSource(): void
    {
        [$uid, $job]         = $this->seed($this->reviewRecord('passed'));
        $brief               = $this->reviewRecord('passed');
        $brief['sourceHash'] = hash('sha256', 'changed original source');
        $this
            ->pool()
            ->getConnectionForTable(self::JOB)
            ->update(
                self::JOB,
                ['source_review' => json_encode($brief, JSON_THROW_ON_ERROR)],
                ['uid' => $job],
            );
        $channel = $this->channel();

        (new DuePostPublisher(
            $this->pool(),
            $channel,
            $this->policy('enforce'),
            new SourceReviewPointer($this->pool()),
        ))->publishDue(
            self::NOW,
        );

        self::assertSame([], $channel->sent);
        self::assertSame('failed', $this->artifact($uid)['publish_status']);
    }

    public function testPersistedEnforcementCannotBeSilentlyDisabledAtPublication(): void
    {
        [$uid]   = $this->seed($this->reviewRecord('flagged', 'enforce'));
        $channel = $this->channel();

        (new DuePostPublisher(
            $this->pool(),
            $channel,
            $this->policy('off'),
            new SourceReviewPointer($this->pool()),
        ))->publishDue(
            self::NOW,
        );

        self::assertSame([], $channel->sent);
        self::assertSame('failed', $this->artifact($uid)['publish_status']);
    }

    public function testObserveKeepsTheWarningAndAllowsExplicitHumanApprovalAndPublishing(): void
    {
        [$uid] = $this->seed(
            $this->reviewRecord('flagged', 'observe'),
            $this->reviewRecord('flagged', 'observe'),
        );
        $policy = $this->policy('observe');
        $review = new ArtifactReviewService(
            $this->pool(),
            $policy,
            new SourceReviewPointer($this->pool()),
        );
        $review->review($uid, ReviewStatus::Approved, 9, self::NOW);
        $review->schedule($uid, self::NOW);

        $channel = $this->channel();

        (new DuePostPublisher(
            $this->pool(),
            $channel,
            $policy,
            new SourceReviewPointer($this->pool()),
        ))->publishDue(
            self::NOW,
        );

        self::assertCount(1, $channel->sent);
        self::assertSame(
            'flagged',
            json_decode(
                $this->artifact($uid)['source_review'],
                true,
                512,
                JSON_THROW_ON_ERROR,
            )['status'],
        );
    }

    public function testAnAcceptedMatchingReviewAndHumanApprovalAllowPublishingOnce(): void
    {
        [$uid]     = $this->seed($this->reviewRecord('passed'));
        $channel   = $this->channel();
        $publisher = new DuePostPublisher(
            $this->pool(),
            $channel,
            $this->policy('enforce'),
            new SourceReviewPointer($this->pool()),
        );

        self::assertSame(1, $publisher->publishDue(self::NOW)->published);
        self::assertSame(0, $publisher->publishDue(self::NOW)->published);
        self::assertCount(1, $channel->sent);
        self::assertSame(self::TEXT, $channel->sent[0]->text);
        self::assertSame('published', $this->artifact($uid)['publish_status']);
    }

    public function testAChangeBetweenReadingTheScheduleAndClaimingDoesNotPublishAStaleSnapshot(): void
    {
        [$uid]   = $this->seed($this->reviewRecord('passed'));
        $channel = new class ($this->pool(), $uid) implements SocialPublisherInterface {
            public bool $sent = false;

            public function __construct(
                private readonly ConnectionPool $pool,
                private readonly int $uid,
            ) {}

            public function isConfigured(): bool
            {
                $this->pool
                    ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
                    ->update(
                        'tx_nrrepurpose_domain_model_artifact',
                        ['script_text' => 'changed after schedule read'],
                        ['uid' => $this->uid],
                    );

                return true;
            }

            public function publish(SocialPost $post): void
            {
                $this->sent = true;
            }
        };

        (new DuePostPublisher(
            $this->pool(),
            $channel,
            $this->policy('enforce'),
            new SourceReviewPointer($this->pool()),
        ))->publishDue(
            self::NOW,
        );

        self::assertFalse($channel->sent);
    }

    /** @return array<string, mixed> */
    private function reviewRecord(string $status, string $mode = 'enforce'): array
    {
        return [
            'status'         => $status,
            'mode'           => $mode,
            'candidateHash'  => hash('sha256', self::TEXT),
            'sourceHash'     => hash('sha256', 'original source'),
            'profile'        => 'nr_repurpose.source_fidelity',
            'profileVersion' => 1,
            'dataClass'      => 'editorContent',
            'configuration'  => '',
            'model'          => 'test-judge',
            'provider'       => 'test-provider',
            'evaluatedAt'    => self::NOW,
            'reason'         => '',
            'answers'        => ['supported' => 1.0, 'qualifications' => 1.0],
        ];
    }

    /** @param array<string, mixed> $review @param array<string, mixed>|null $brief @return array{int, int} */
    private function seed(array $review, ?array $brief = null): array
    {
        $connection = $this->pool()->getConnectionForTable(self::JOB);
        $connection->insert(
            self::JOB,
            [
                'pid'          => 0,
                'status'       => 'done',
                'source_type'  => 'url',
                'source_value' => 'https://example.org/',
            ],
        );
        $job    = (int) $connection->lastInsertId();
        $jobRow = $connection
            ->select(['*'], self::JOB, ['uid' => $job])
            ->fetchAssociative();
        self::assertIsArray($jobRow);
        $brief ??= $this->reviewRecord('passed');
        $brief['sourcePointerHash'] = (new SourceReviewPointer($this->pool()))->fingerprint($jobRow);
        $connection->update(
            self::JOB,
            ['source_review' => json_encode($brief, JSON_THROW_ON_ERROR)],
            ['uid' => $job],
        );
        $connection = $this->pool()->getConnectionForTable(self::ARTIFACT);
        $connection->insert(
            self::ARTIFACT,
            [
                'pid'            => 0,
                'job'            => $job,
                'type'           => 'social_post',
                'variant'        => 'linkedin',
                'status'         => 'done',
                'script_text'    => self::TEXT,
                'review_status'  => 'approved',
                'publish_status' => 'scheduled',
                'publish_at'     => self::NOW,
                'source_review'  => json_encode($review, JSON_THROW_ON_ERROR),
            ],
        );

        return [(int) $connection->lastInsertId(), $job];
    }

    /** @return array<string, mixed> */
    private function artifact(int $uid): array
    {
        $row = $this
            ->pool()
            ->getConnectionForTable(self::ARTIFACT)
            ->select(['*'], self::ARTIFACT, ['uid' => $uid])
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function pool(): ConnectionPool
    {
        return GeneralUtility::makeInstance(ConnectionPool::class);
    }

    private function policy(
        string $mode,
        float $minimumScore = 0.8,
    ): SourceReviewPolicy {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration
            ->method('get')
            ->willReturnCallback(
                static fn (
                    string $extension,
                    string $name,
                ): mixed => match ($name) {
                    'sourceReviewMode'         => $mode,
                    'sourceReviewMinimumScore' => $minimumScore,
                    'sourceReviewDataClass'    => 'editorContent',
                    default                    => '',
                },
            );

        return new SourceReviewPolicy($configuration);
    }

    private function channel(): SocialPublisherInterface
    {
        return new class implements SocialPublisherInterface {
            /** @var list<SocialPost> */
            public array $sent = [];

            public function isConfigured(): bool
            {
                return true;
            }

            public function publish(SocialPost $post): void
            {
                $this->sent[] = $post;
            }
        };
    }

    public function testPublicationRechecksTheCurrentThreshold(): void
    {
        $review            = $this->reviewRecord('passed');
        $review['answers'] = ['supported' => 0.9, 'qualifications' => 0.9];
        [$uid]             = $this->seed($review);
        $channel           = $this->channel();

        (new DuePostPublisher(
            $this->pool(),
            $channel,
            $this->policy('enforce', 0.95),
            new SourceReviewPointer($this->pool()),
        ))->publishDue(self::NOW);

        self::assertSame([], $channel->sent);
        self::assertSame('failed', $this->artifact($uid)['publish_status']);
    }

    public function testAConfigurationFailureAfterClaimFailsClosedWithoutLeavingPublishingStuck(): void
    {
        [$uid]         = $this->seed($this->reviewRecord('passed'));
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration
            ->method('get')
            ->willThrowException(new RuntimeException('configuration unavailable'));
        $channel = $this->channel();

        $report = (new DuePostPublisher(
            $this->pool(),
            $channel,
            new SourceReviewPolicy($configuration),
            new SourceReviewPointer($this->pool()),
        ))->publishDue(self::NOW);

        self::assertSame([], $channel->sent);
        self::assertSame(1, $report->failed);
        self::assertSame('failed', $this->artifact($uid)['publish_status']);
    }

    public function testLegacyApprovalWithoutAnAutomaticAssessmentDoesNotPassEnforcement(): void
    {
        [$uid]   = $this->seed([]);
        $channel = $this->channel();

        (new DuePostPublisher(
            $this->pool(),
            $channel,
            $this->policy('enforce'),
            new SourceReviewPointer($this->pool()),
        ))->publishDue(
            self::NOW,
        );

        self::assertSame([], $channel->sent);
        self::assertSame('failed', $this->artifact($uid)['publish_status']);
    }

    /** @return array<string, array{string, int|string}> */
    public static function changedSourcePointers(): array
    {
        return [
            'URL'        => ['source_value', 'https://example.org/changed'],
            'public URL' => ['source_url', 'https://example.org/new-public'],
            'type'       => ['source_type', 'pdf_url'],
            'page depth' => ['source_depth', 2],
            'site'       => ['site', 'different-site'],
        ];
    }

    #[DataProvider('changedSourcePointers')]
    public function testAnEditedSourcePointerInvalidatesTheUnchangedStoredReview(
        string $field,
        int|string $value,
    ): void {
        [$uid, $job] = $this->seed($this->reviewRecord('passed'));
        $connection  = $this->pool()->getConnectionForTable(self::JOB);
        $before      = $connection
            ->select(['source_review'], self::JOB, ['uid' => $job])
            ->fetchOne();
        $connection->update(self::JOB, [$field => $value], ['uid' => $job]);
        self::assertSame(
            $before,
            $connection
                ->select(['source_review'], self::JOB, ['uid' => $job])
                ->fetchOne(),
        );
        $channel = $this->channel();

        (new DuePostPublisher(
            $this->pool(),
            $channel,
            $this->policy('enforce'),
            new SourceReviewPointer($this->pool()),
        ))->publishDue(self::NOW);

        self::assertSame([], $channel->sent);
        self::assertSame('failed', $this->artifact($uid)['publish_status']);
    }

    public function testTheSourcePointerIsAlsoCheckedWhenAnEditorApproves(): void
    {
        [$uid, $job] = $this->seed($this->reviewRecord('passed'));
        $this
            ->pool()
            ->getConnectionForTable(self::JOB)
            ->update(
                self::JOB,
                ['source_value' => 'https://example.org/changed'],
                ['uid' => $job],
            );
        $this->expectException(ReviewRefusedException::class);
        $this->expectExceptionMessage('review.refused.sourceReview');

        (new ArtifactReviewService(
            $this->pool(),
            $this->policy('enforce'),
            new SourceReviewPointer($this->pool()),
        ))->review($uid, ReviewStatus::Approved, 9, self::NOW);
    }

    public function testReplacingTheFalFileWithTheSameReferenceCountInvalidatesTheAssessment(): void
    {
        [$uid, $job] = $this->seed($this->reviewRecord('passed'));
        $jobs        = $this->pool()->getConnectionForTable(self::JOB);
        $jobs->update(
            self::JOB,
            [
                'source_type'  => 'pdf_fal',
                'source_value' => '',
                'source_pdf'   => 1,
            ],
            ['uid' => $job],
        );
        $files = $this->pool()->getConnectionForTable('sys_file');
        $files->insert(
            'sys_file',
            ['identifier' => '/original.pdf', 'sha1' => '1111111111111111111111111111111111111111'],
        );
        $original = (int) $files->lastInsertId();
        $files->insert(
            'sys_file',
            ['identifier' => '/replacement.pdf', 'sha1' => '2222222222222222222222222222222222222222'],
        );
        $replacement = (int) $files->lastInsertId();
        $references  = $this->pool()->getConnectionForTable('sys_file_reference');
        $references->insert(
            'sys_file_reference',
            [
                'uid_local'       => $original,
                'uid_foreign'     => $job,
                'tablenames'      => self::JOB,
                'fieldname'       => 'source_pdf',
                'sorting_foreign' => 1,
            ],
        );
        $reference = (int) $references->lastInsertId();
        $jobRow    = $jobs->select(['*'], self::JOB, ['uid' => $job])->fetchAssociative();
        self::assertIsArray($jobRow);
        $brief                      = $this->reviewRecord('passed');
        $brief['sourcePointerHash'] = (new SourceReviewPointer($this->pool()))->fingerprint($jobRow);
        $jobs->update(
            self::JOB,
            ['source_review' => json_encode($brief, JSON_THROW_ON_ERROR)],
            ['uid' => $job],
        );
        $references->update(
            'sys_file_reference',
            ['uid_local' => $replacement],
            ['uid' => $reference],
        );
        self::assertSame(
            1,
            (int) $jobs
                ->select(['source_pdf'], self::JOB, ['uid' => $job])
                ->fetchOne(),
        );
        $channel = $this->channel();

        (new DuePostPublisher(
            $this->pool(),
            $channel,
            $this->policy('enforce'),
            new SourceReviewPointer($this->pool()),
        ))->publishDue(self::NOW);

        self::assertSame([], $channel->sent);
        self::assertSame('failed', $this->artifact($uid)['publish_status']);
    }

    /** @return array<string, array{string}> */
    public static function editorialChanges(): array
    {
        return [
            'approve'    => ['approve'],
            'reject'     => ['reject'],
            'schedule'   => ['schedule'],
            'unschedule' => ['unschedule'],
        ];
    }

    #[DataProvider('editorialChanges')]
    public function testAnEditorialWriteCannotOverwriteAConcurrentPublishingClaim(
        string $action,
    ): void {
        [$uid]      = $this->seed($this->reviewRecord('passed'));
        $realPool   = $this->pool();
        $real       = $realPool->getConnectionForTable(self::ARTIFACT);
        $connection = $this->createStub(Connection::class);
        $connection->method('select')->willReturnCallback($real->select(...));
        $connection
            ->method('update')
            ->willReturnCallback(
                static function (
                    string $table,
                    array $fields,
                    array $criteria,
                ) use ($real, $uid): int {
                    // The editor already read scheduled; another worker claims before its write.
                    $real->update(
                        $table,
                        ['publish_status' => 'publishing'],
                        ['uid' => $uid],
                    );

                    return $real->update($table, $fields, $criteria);
                },
            );
        $pool = $this->createStub(ConnectionPool::class);
        $pool
            ->method('getConnectionForTable')
            ->willReturnCallback(
                static fn (
                    string $table,
                ): Connection => $table === self::ARTIFACT ? $connection : $realPool->getConnectionForTable($table),
            );
        $service = new ArtifactReviewService(
            $pool,
            $this->policy('enforce'),
            new SourceReviewPointer($realPool),
        );
        try {
            match ($action) {
                'approve'  => $service->review($uid, ReviewStatus::Approved, 9, self::NOW),
                'reject'   => $service->review($uid, ReviewStatus::Rejected, 9, self::NOW),
                'schedule' => $service->schedule($uid, self::NOW + 60),
                default    => $service->unschedule($uid),
            };
            self::fail('A stale editorial write must be refused.');
        } catch (ReviewRefusedException $exception) {
            self::assertSame('review.refused.changed', $exception->getMessage());
        }

        self::assertSame('publishing', $this->artifact($uid)['publish_status']);
        self::assertSame('approved', $this->artifact($uid)['review_status']);
    }

    public function testPublicationRechecksApprovalAfterClaiming(): void
    {
        [$uid]      = $this->seed($this->reviewRecord('passed'));
        $realPool   = $this->pool();
        $real       = $realPool->getConnectionForTable(self::ARTIFACT);
        $connection = $this->createStub(Connection::class);
        $connection->method('select')->willReturnCallback($real->select(...));
        $connection
            ->method('update')
            ->willReturnCallback(
                static function (
                    string $table,
                    array $fields,
                    array $criteria,
                ) use ($real, $uid): int {
                    $affected = $real->update($table, $fields, $criteria);
                    if (($fields['publish_status'] ?? null) === 'publishing') {
                        $real->update(
                            $table,
                            ['review_status' => 'rejected'],
                            ['uid' => $uid],
                        );
                    }

                    return $affected;
                },
            );
        $pool = $this->createStub(ConnectionPool::class);
        $pool
            ->method('getConnectionForTable')
            ->willReturnCallback(
                static fn (
                    string $table,
                ): Connection => $table === self::ARTIFACT ? $connection : $realPool->getConnectionForTable($table),
            );
        $pool
            ->method('getQueryBuilderForTable')
            ->willReturnCallback($realPool->getQueryBuilderForTable(...));
        $channel = $this->channel();
        $report  = (new DuePostPublisher(
            $pool,
            $channel,
            $this->policy('enforce'),
            new SourceReviewPointer($realPool),
        ))->publishDue(self::NOW);
        self::assertSame([], $channel->sent);
        self::assertSame(1, $report->failed);
        self::assertSame('rejected', $this->artifact($uid)['review_status']);
    }

    public function testDefaultOffKeepsLegacySocialReviewAndSchedulingAvailableWithSqlNullAssessment(): void
    {
        [$uid] = $this->seed([], $this->reviewRecord('not_run', 'off'));
        $this
            ->pool()
            ->getConnectionForTable(self::ARTIFACT)
            ->update(self::ARTIFACT, ['source_review' => null], ['uid' => $uid]);
        $service = new ArtifactReviewService(
            $this->pool(),
            $this->policy('off'),
            new SourceReviewPointer($this->pool()),
        );
        $service->review($uid, ReviewStatus::Approved, 9, self::NOW);
        $service->schedule($uid, self::NOW + 60);
        $service->unschedule($uid);
        $service->review($uid, ReviewStatus::Rejected, 9, self::NOW);
        self::assertNull($this->artifact($uid)['source_review']);
        self::assertSame('rejected', $this->artifact($uid)['review_status']);
        self::assertSame('', $this->artifact($uid)['publish_status']);
    }

    public function testDefaultOffKeepsLegacyMediaApprovalAvailableWithSqlNullScriptAndAssessment(): void
    {
        [$uid] = $this->seed([], $this->reviewRecord('not_run', 'off'));
        $this
            ->pool()
            ->getConnectionForTable(self::ARTIFACT)
            ->update(
                self::ARTIFACT,
                [
                    'type'           => 'schaubild',
                    'script_text'    => null,
                    'source_review'  => null,
                    'publish_status' => '',
                ],
                ['uid' => $uid],
            );
        $service = new ArtifactReviewService(
            $this->pool(),
            $this->policy('off'),
            new SourceReviewPointer($this->pool()),
        );
        $service->review($uid, ReviewStatus::Approved, 9, self::NOW);
        self::assertSame('approved', $this->artifact($uid)['review_status']);
        self::assertNull($this->artifact($uid)['script_text']);
        self::assertNull($this->artifact($uid)['source_review']);
    }
}
