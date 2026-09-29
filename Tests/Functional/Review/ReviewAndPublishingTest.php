<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Review;

use Netresearch\NrRepurpose\Command\PublishDueCommand;
use Netresearch\NrRepurpose\Domain\Enum\ReviewStatus;
use Netresearch\NrRepurpose\Review\ArtifactReviewService;
use Netresearch\NrRepurpose\Review\ReviewRefusedException;
use Netresearch\NrRepurpose\Social\DuePostPublisher;
use Netresearch\NrRepurpose\Social\SocialPost;
use Netresearch\NrRepurpose\Social\SocialPublisherInterface;
use Netresearch\NrRepurpose\Social\SocialPublishException;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The approval step and its reader, the publishing of due social posts, against a
 * real database. The publishing channel is a recording double.
 */
#[CoversClass(ArtifactReviewService::class)]
#[CoversClass(DuePostPublisher::class)]
#[CoversClass(PublishDueCommand::class)]
final class ReviewAndPublishingTest extends AbstractFunctionalTestCase
{
    private const int NOW = 1_790_000_000;

    private const string TABLE = 'tx_nrrepurpose_domain_model_artifact';

    private int $job = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $connection->insert('tx_nrrepurpose_domain_model_job', ['pid' => 0, 'source_type' => 'url', 'source_value' => 'https://example.com/report', 'status' => 'done']);

        $this->job = (int) $connection->lastInsertId();
    }

    public function testApprovingAFinishedArtifactRecordsWhoAndWhen(): void
    {
        $uid = $this->artifact('faq');

        self::assertSame($this->job, $this->service()->review($uid, ReviewStatus::Approved, 5, self::NOW));

        self::assertSame(['approved', 5, self::NOW], $this->fields($uid, 'review_status', 'reviewed_by', 'reviewed_at'));
    }

    public function testAnArtifactThatIsNotDoneCannotBeReviewed(): void
    {
        $uid = $this->artifact('faq', status: 'failed');

        $this->expectRefusal('review.refused.notDone', fn (): int => $this->service()->review($uid, ReviewStatus::Approved, 5, self::NOW));
    }

    public function testOnlyAnApprovedSocialPostCanBeScheduled(): void
    {
        $faq  = $this->artifact('faq', review: 'approved');
        $post = $this->artifact('social_post');

        $this->expectRefusal('review.refused.notSocial', fn (): int => $this->service()->schedule($faq, self::NOW));
        $this->expectRefusal('review.refused.notApproved', fn (): int => $this->service()->schedule($post, self::NOW));

        $this->service()->review($post, ReviewStatus::Approved, 5, self::NOW);
        $this->expectRefusal('review.refused.noTime', fn (): int => $this->service()->schedule($post, 0));
        self::assertSame($this->job, $this->service()->schedule($post, self::NOW + 3600));
        self::assertSame([self::NOW + 3600, 'scheduled'], $this->fields($post, 'publish_at', 'publish_status'));
    }

    public function testRejectingAScheduledPostTakesItOffTheSchedule(): void
    {
        $post = $this->artifact('social_post', review: 'approved', publishAt: self::NOW, publishStatus: 'scheduled');

        $this->service()->review($post, ReviewStatus::Rejected, 5, self::NOW);

        self::assertSame(['rejected', 0, ''], $this->fields($post, 'review_status', 'publish_at', 'publish_status'));
    }

    public function testAPublishedPostKeepsItsReviewAndSchedule(): void
    {
        $post = $this->artifact('social_post', review: 'approved', publishAt: self::NOW, publishStatus: 'published');

        $this->expectRefusal('review.refused.published', fn (): int => $this->service()->review($post, ReviewStatus::Rejected, 5, self::NOW));
        $this->expectRefusal('review.refused.published', fn (): int => $this->service()->schedule($post, self::NOW));
        $this->expectRefusal('review.refused.published', fn (): int => $this->service()->unschedule($post));
    }

    public function testThePlanListsScheduledPostsOldestFirstWithTheirSource(): void
    {
        $later   = $this->artifact('social_post', variant: 'x', review: 'approved', publishAt: self::NOW + 60, publishStatus: 'scheduled');
        $earlier = $this->artifact('social_post', variant: 'linkedin', review: 'approved', publishAt: self::NOW, publishStatus: 'failed', error: 'HTTP 500');
        $this->artifact('social_post', variant: 'instagram', review: 'approved');
        $this->artifact('faq', review: 'approved');

        $plan = $this->service()->planned();

        self::assertSame([$earlier, $later], array_column($plan, 'uid'));
        self::assertSame('HTTP 500', $plan[0]['publish_error']);
        self::assertSame('https://example.com/report', $plan[0]['source_value']);
    }

    public function testDuePostsGoOutOnceAndRefusedOnesAreMarkedFailed(): void
    {
        $due      = $this->artifact('social_post', variant: 'linkedin', review: 'approved', publishAt: self::NOW - 60, publishStatus: 'scheduled');
        $refused  = $this->artifact('social_post', variant: 'x', review: 'approved', publishAt: self::NOW, publishStatus: 'scheduled');
        $future   = $this->artifact('social_post', variant: 'instagram', review: 'approved', publishAt: self::NOW + 60, publishStatus: 'scheduled');
        $rejected = $this->artifact('social_post', variant: 'linkedin', review: 'rejected', publishAt: self::NOW - 60, publishStatus: 'scheduled');
        $channel  = $this->channel(refuse: 'x');

        $report = (new DuePostPublisher(GeneralUtility::makeInstance(ConnectionPool::class), $channel))->publishDue(self::NOW);

        self::assertSame([2, 1, 1], [$report->due, $report->published, $report->failed]);
        self::assertSame([$due, $refused], array_map(static fn (SocialPost $post): int => $post->artifactUid, $channel->sent));
        self::assertSame('Post text linkedin', $channel->sent[0]->text);
        self::assertSame('https://example.com/report', $channel->sent[0]->sourceUrl);
        self::assertTrue($channel->sent[0]->aiLabel['aiGenerated']);
        self::assertSame(['published', self::NOW], $this->fields($due, 'publish_status', 'published_at'));
        self::assertSame(['failed', 'channel refused x'], $this->fields($refused, 'publish_status', 'publish_error'));
        self::assertSame('scheduled', $this->fields($future, 'publish_status')[0]);
        self::assertSame('scheduled', $this->fields($rejected, 'publish_status')[0]);

        $again = (new DuePostPublisher(GeneralUtility::makeInstance(ConnectionPool::class), $channel))->publishDue(self::NOW);
        self::assertSame(0, $again->due);
        self::assertCount(2, $channel->sent);
    }

    /**
     * The source URL is published with the post, so it keeps its query (index.php?id=5 is
     * the page's address) and its fragment, but not the user name and password the job
     * stores for the fetch.
     */
    public function testThePublishedSourceUrlCarriesNoCredentials(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->update('tx_nrrepurpose_domain_model_job', ['source_value' => 'https://user:secret@example.com/a?id=5#top'], ['uid' => $this->job]);
        $this->artifact('social_post', review: 'approved', publishAt: self::NOW, publishStatus: 'scheduled');
        $channel = $this->channel();

        (new DuePostPublisher(GeneralUtility::makeInstance(ConnectionPool::class), $channel))->publishDue(self::NOW);

        self::assertCount(1, $channel->sent);
        self::assertSame('https://example.com/a?id=5#top', $channel->sent[0]->sourceUrl);
        $payload = json_encode($channel->sent[0]->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('secret', $payload);
        self::assertStringNotContainsString('user:', $payload);
    }

    public function testAPostAnotherRunHasClaimedIsNotSentAgain(): void
    {
        $this->artifact('social_post', review: 'approved', publishAt: self::NOW, publishStatus: 'publishing');
        $channel = $this->channel();

        $report = (new DuePostPublisher(GeneralUtility::makeInstance(ConnectionPool::class), $channel))->publishDue(self::NOW);

        self::assertSame(0, $report->due);
        self::assertSame([], $channel->sent);
    }

    public function testWithoutAChannelTheCommandReportsTheDuePostsAndChangesNothing(): void
    {
        $post = $this->artifact('social_post', review: 'approved', publishAt: 1, publishStatus: 'scheduled');

        $tester = new CommandTester($this->get(PublishDueCommand::class));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 post(s) due, none sent: no publishing channel configured', $tester->getDisplay());
        self::assertSame('scheduled', $this->fields($post, 'publish_status')[0]);
    }

    private function service(): ArtifactReviewService
    {
        return new ArtifactReviewService(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function artifact(
        string $type,
        string $variant = 'default',
        string $status = 'done',
        string $review = '',
        int $publishAt = 0,
        string $publishStatus = '',
        string $error = '',
    ): int {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid'            => 0,
            'job'            => $this->job,
            'type'           => $type,
            'variant'        => $variant,
            'status'         => $status,
            'script_text'    => 'Post text ' . $variant,
            'metadata'       => json_encode(['aiLabel' => ['aiGenerated' => true]], JSON_THROW_ON_ERROR),
            'review_status'  => $review,
            'publish_at'     => $publishAt,
            'publish_status' => $publishStatus,
            'publish_error'  => $error,
        ]);

        return (int) $connection->lastInsertId();
    }

    /**
     * @return list<int|string>
     */
    private function fields(int $uid, string ...$columns): array
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE)
            ->select($columns, self::TABLE, ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row);

        return array_map(static fn (mixed $value): int|string => is_numeric($value) ? (int) $value : (string) $value, array_values($row));
    }

    private function expectRefusal(string $label, callable $call): void
    {
        try {
            $call();
            self::fail('Expected a refusal: ' . $label);
        } catch (ReviewRefusedException $e) {
            self::assertSame($label, $e->getMessage());
        }
    }

    private function channel(string $refuse = ''): SocialPublisherInterface
    {
        return new class ($refuse) implements SocialPublisherInterface {
            /** @var list<SocialPost> */
            public array $sent = [];

            public function __construct(private readonly string $refuse) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function publish(SocialPost $post): void
            {
                $this->sent[] = $post;
                if ($post->platform === $this->refuse) {
                    throw new SocialPublishException('channel refused ' . $post->platform, 1);
                }
            }
        };
    }
}
