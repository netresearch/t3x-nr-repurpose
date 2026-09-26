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
    public const TABLE = 'tx_nrrepurpose_domain_model_artifact';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Records the decision and returns the artifact's job uid. A rejection also takes
     * a scheduled post off the schedule; a post already published or on its way out
     * keeps its decision.
     */
    public function review(int $artifactUid, ReviewStatus $decision, int $reviewerUid, int $now): int
    {
        $row = $this->row($artifactUid);
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

        $this->update($artifactUid, $fields);

        return $row['job'];
    }

    /**
     * Schedules an approved social post and returns its job uid. A time in the past is
     * accepted: the post goes out on the next run of the command.
     */
    public function schedule(int $artifactUid, int $publishAt): int
    {
        $row = $this->row($artifactUid);
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

        $this->update($artifactUid, [
            'publish_at'     => $publishAt,
            'publish_status' => PublishStatus::Scheduled->value,
            'publish_error'  => '',
        ]);

        return $row['job'];
    }

    /** Takes a scheduled or failed post off the schedule and returns its job uid. */
    public function unschedule(int $artifactUid): int
    {
        $row = $this->row($artifactUid);
        if (in_array($row['publish_status'], [PublishStatus::Publishing->value, PublishStatus::Published->value], true)) {
            throw new ReviewRefusedException('review.refused.published', 1790400002);
        }

        $this->update($artifactUid, ['publish_at' => 0, 'publish_status' => PublishStatus::None->value]);

        return $row['job'];
    }

    /**
     * The social posts on the schedule or past it, oldest publishing time first, with
     * their job's source.
     *
     * @return list<array{uid: int, job: int, variant: string, script_text: string, publish_at: int, publish_status: string, published_at: int, publish_error: string, source_value: string}>
     */
    public function planned(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows         = $queryBuilder
            ->select('a.uid', 'a.job', 'a.variant', 'a.script_text', 'a.publish_at', 'a.publish_status', 'a.published_at', 'a.publish_error', 'j.source_value')
            ->from(self::TABLE, 'a')
            ->join('a', 'tx_nrrepurpose_domain_model_job', 'j', $queryBuilder->expr()->eq('j.uid', $queryBuilder->quoteIdentifier('a.job')))
            ->where(
                $queryBuilder->expr()->eq('a.type', $queryBuilder->createNamedParameter(ArtifactType::SocialPost->value)),
                $queryBuilder->expr()->neq('a.publish_status', $queryBuilder->createNamedParameter(PublishStatus::None->value)),
            )
            ->orderBy('a.publish_at')
            ->addOrderBy('a.uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'uid'            => (int) $row['uid'],
            'job'            => (int) $row['job'],
            'variant'        => (string) $row['variant'],
            'script_text'    => (string) $row['script_text'],
            'publish_at'     => (int) $row['publish_at'],
            'publish_status' => (string) $row['publish_status'],
            'published_at'   => (int) $row['published_at'],
            'publish_error'  => (string) $row['publish_error'],
            'source_value'   => (string) $row['source_value'],
        ], $rows);
    }

    /**
     * @return array{job: int, type: string, status: string, review_status: string, publish_status: string}
     */
    private function row(int $artifactUid): array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['job', 'type', 'status', 'review_status', 'publish_status'], self::TABLE, ['uid' => $artifactUid])
            ->fetchAssociative();
        if (!is_array($row)) {
            throw new ReviewRefusedException('review.refused.notFound', 1790400006);
        }

        return [
            'job'            => (int) $row['job'],
            'type'           => (string) $row['type'],
            'status'         => (string) $row['status'],
            'review_status'  => (string) $row['review_status'],
            'publish_status' => (string) $row['publish_status'],
        ];
    }

    /**
     * @param array<string, int|string> $fields
     */
    private function update(int $artifactUid, array $fields): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, $fields, ['uid' => $artifactUid]);
    }
}
