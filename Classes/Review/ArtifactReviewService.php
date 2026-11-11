<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Review;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\PublishStatus;
use Netresearch\NrRepurpose\Domain\Enum\ReviewStatus;
use Netresearch\NrRepurpose\Ingestion\SourceUrlRedactor;
use Netresearch\NrRepurpose\SourceReview\SourceReviewPointer;
use Netresearch\NrRepurpose\SourceReview\SourceReviewPolicy;
use Netresearch\NrRepurpose\SourceReview\SourceReviewResult;
use Netresearch\NrRepurpose\Utility\ScalarCast;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The approval step between generation and use: an editor approves or rejects a
 * finished artifact, and only an approved social post can be scheduled for publishing
 * (nr_repurpose:publish-due sends it when it is due).
 *
 * Every refusal is a ReviewRefusedException naming a label; the result view shows it.
 */
final readonly class ArtifactReviewService
{
    public const string TABLE = 'tx_nrrepurpose_domain_model_artifact';

    public function __construct(
        private ConnectionPool $connectionPool,
        private SourceReviewPolicy $sourceReviewPolicy,
        private SourceReviewPointer $sourceReviewPointer,
    ) {}

    /**
     * Records the decision and returns the artifact's job uid. A rejection also takes
     * a scheduled post off the schedule; a post already published or on its way out
     * keeps its decision.
     */
    public function review(int $artifactUid, ReviewStatus $decision, int $reviewerUid, int $now): int
    {
        $row = $this->row($artifactUid);
        if ($decision === ReviewStatus::Approved) {
            $this->assertSourceReview(
                $row['job'],
                ScalarCast::string($row['source_review']),
                ScalarCast::string($row['script_text']),
            );
        }

        if ($row['status'] !== ArtifactStatus::Done->value) {
            throw new ReviewRefusedException('review.refused.notDone', 1790400001);
        }

        if (in_array($row['publish_status'], [PublishStatus::Publishing->value, PublishStatus::Published->value], true)) {
            throw new ReviewRefusedException('review.refused.published', 1790400002);
        }

        $fields = [
            'review_status' => $decision->value,
            'reviewed_by'   => $reviewerUid,
            'reviewed_at'   => $now,
        ];
        if ($decision !== ReviewStatus::Approved) {
            $fields += ['publish_status' => PublishStatus::None->value, 'publish_at' => 0];
        }

        $this->update($artifactUid, $fields, $row);

        return $row['job'];
    }

    /**
     * Schedules an approved social post and returns its job uid. A time in the past is
     * accepted: the post goes out on the next run of the command.
     */
    public function schedule(int $artifactUid, int $publishAt): int
    {
        $row = $this->row($artifactUid);
        $this->assertSourceReview(
            $row['job'],
            ScalarCast::string($row['source_review']),
            ScalarCast::string($row['script_text']),
        );
        if ($row['type'] !== ArtifactType::SocialPost->value) {
            throw new ReviewRefusedException('review.refused.notSocial', 1790400003);
        }

        if ($row['review_status'] !== ReviewStatus::Approved->value) {
            throw new ReviewRefusedException('review.refused.notApproved', 1790400004);
        }

        if (in_array($row['publish_status'], [PublishStatus::Publishing->value, PublishStatus::Published->value], true)) {
            throw new ReviewRefusedException('review.refused.published', 1790400002);
        }

        if ($publishAt <= 0) {
            throw new ReviewRefusedException('review.refused.noTime', 1790400005);
        }

        $this->update(
            $artifactUid,
            [
                'publish_at'     => $publishAt,
                'publish_status' => PublishStatus::Scheduled->value,
                'publish_error'  => '',
            ],
            $row,
        );

        return $row['job'];
    }

    /** Takes a scheduled or failed post off the schedule and returns its job uid. */
    public function unschedule(int $artifactUid): int
    {
        $row = $this->row($artifactUid);
        if (in_array($row['publish_status'], [PublishStatus::Publishing->value, PublishStatus::Published->value], true)) {
            throw new ReviewRefusedException('review.refused.published', 1790400002);
        }

        $this->update(
            $artifactUid,
            ['publish_at' => 0, 'publish_status' => PublishStatus::None->value],
            $row,
        );

        return $row['job'];
    }

    /**
     * The social posts on the schedule or past it, oldest publishing time first, with
     * their job's source as the publishing plan shows it: without user name, password,
     * query and fragment (SourceUrlRedactor), empty for a job without a URL. With
     * $ownerUid only the posts of the jobs that backend user created.
     *
     * @return list<array{uid: int, job: int, variant: string, script_text: string, publish_at: int, publish_status: string, published_at: int, publish_error: string, source_value: string}>
     */
    public function planned(?int $ownerUid = null): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->select('a.uid', 'a.job', 'a.variant', 'a.script_text', 'a.publish_at', 'a.publish_status', 'a.published_at', 'a.publish_error', 'j.source_value', 'j.source_url')
            ->from(self::TABLE, 'a')
            ->join('a', 'tx_nrrepurpose_domain_model_job', 'j', $queryBuilder->expr()->eq('j.uid', $queryBuilder->quoteIdentifier('a.job')))
            ->where(
                $queryBuilder->expr()->eq('a.type', $queryBuilder->createNamedParameter(ArtifactType::SocialPost->value)),
                $queryBuilder->expr()->neq('a.publish_status', $queryBuilder->createNamedParameter(PublishStatus::None->value)),
            );
        if ($ownerUid !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('j.be_user', $queryBuilder->createNamedParameter($ownerUid, Connection::PARAM_INT)));
        }

        $rows = $queryBuilder
            ->orderBy('a.publish_at')
            ->addOrderBy('a.uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn (array $row): array => [
            'uid'            => ScalarCast::int($row['uid']),
            'job'            => ScalarCast::int($row['job']),
            'variant'        => ScalarCast::string($row['variant']),
            'script_text'    => ScalarCast::string($row['script_text']),
            'publish_at'     => ScalarCast::int($row['publish_at']),
            'publish_status' => ScalarCast::string($row['publish_status']),
            'published_at'   => ScalarCast::int($row['published_at']),
            'publish_error'  => ScalarCast::string($row['publish_error']),
            'source_value'   => $this->shownSource(ScalarCast::string($row['source_url']), ScalarCast::string($row['source_value'])),
        ], $rows);
    }

    /** A page job's source is its public URL; a job with neither URL shows nothing. */
    private function shownSource(string $publicUrl, string $sourceValue): string
    {
        $url = $publicUrl === '' ? $sourceValue : $publicUrl;

        return $url === '' ? '' : SourceUrlRedactor::redact($url);
    }

    /** @return array{job: int, type: string, status: string, review_status: string, publish_status: string, publish_at: int, source_review: ?string, script_text: ?string} */
    private function row(int $artifactUid): array
    {
        $row = $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->select(
                [
                    'job',
                    'type',
                    'status',
                    'review_status',
                    'publish_status',
                    'publish_at',
                    'source_review',
                    'script_text',
                ],
                self::TABLE,
                ['uid' => $artifactUid],
            )
            ->fetchAssociative();
        if (!is_array($row)) {
            throw new ReviewRefusedException('review.refused.notFound', 1790400006);
        }

        return [
            'job'            => ScalarCast::int($row['job']),
            'type'           => ScalarCast::string($row['type']),
            'status'         => ScalarCast::string($row['status']),
            'review_status'  => ScalarCast::string($row['review_status']),
            'publish_status' => ScalarCast::string($row['publish_status']),
            'publish_at'     => ScalarCast::int($row['publish_at']),
            // Keep SQL NULL intact for the compare-and-set on pre-migration rows.
            'source_review' => $row['source_review'] === null ? null : ScalarCast::string($row['source_review']),
            'script_text'   => $row['script_text'] === null ? null : ScalarCast::string($row['script_text']),
        ];
    }

    /**
     * Change only the state the editor actually read. A concurrent publisher's
     * claim or another editor's schedule must win over a stale request.
     *
     * @param array<string, int|string>      $fields
     * @param array<string, int|string|null> $snapshot
     */
    private function update(
        int $artifactUid,
        array $fields,
        array $snapshot,
    ): void {
        $criteria   = ['uid' => $artifactUid] + $snapshot;
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $affected   = $connection->update(self::TABLE, $fields, $criteria);
        if ($affected === 1) {
            return;
        }

        if ($affected === 0) {
            // MySQL may count changed rows instead of matched rows. Accept an
            // unchanged action only while both its original snapshot and all
            // requested values still match, preserving SQL NULL and exact bytes.
            $current = $connection
                ->select(['*'], self::TABLE, ['uid' => $artifactUid])
                ->fetchAssociative();
            if (is_array($current) && $this->matchesFields($current, $criteria) && $this->matchesFields($current, $fields)) {
                return;
            }
        }

        throw new ReviewRefusedException('review.refused.changed', 1793980002);
    }

    private function assertSourceReview(
        int $jobUid,
        string $reviewJson,
        string $candidate,
    ): void {
        $job = $this->connectionPool
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->select(['*'], 'tx_nrrepurpose_domain_model_job', ['uid' => $jobUid])
            ->fetchAssociative();
        $review = SourceReviewResult::fromJson($reviewJson)->toArray();
        $brief  = SourceReviewResult::fromJson(
            ScalarCast::string(is_array($job) ? $job['source_review'] : ''),
        )->toArray();
        if (!is_array($job) || !$this->sourceReviewPolicy->allowsArtifact($review, $candidate, $brief) || !$this->sourceReviewPolicy->allowsSource(
            $brief,
            $this->sourceReviewPointer->fingerprint($job),
            $review,
        )) {
            throw new ReviewRefusedException(
                'review.refused.sourceReview',
                1793980001,
            );
        }
    }

    /**
     * @param array<string, mixed>           $row
     * @param array<string, int|string|null> $expected
     */
    private function matchesFields(array $row, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $row)) {
                return false;
            }

            if ($value === null ? $row[$field] !== null : $row[$field] === null || ScalarCast::string($row[$field]) !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
