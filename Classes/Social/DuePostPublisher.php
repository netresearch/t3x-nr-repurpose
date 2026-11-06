<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Social;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\PublishStatus;
use Netresearch\NrRepurpose\Domain\Enum\ReviewStatus;
use Netresearch\NrRepurpose\Ingestion\SourceUrlRedactor;
use Netresearch\NrRepurpose\SourceReview\SourceReviewPointer;
use Netresearch\NrRepurpose\SourceReview\SourceReviewPolicy;
use Netresearch\NrRepurpose\SourceReview\SourceReviewResult;
use Netresearch\NrRepurpose\Utility\ScalarCast;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Sends every approved social post whose time has come through the publishing
 * channel, once.
 *
 * A post is claimed by one conditional update (scheduled → publishing) before it is
 * sent, so a second run at the same time skips it. Accepted posts become published,
 * refused ones failed with the channel's reason; a failed post stays failed until an
 * editor schedules it again. Without a configured channel nothing is claimed.
 */
final readonly class DuePostPublisher
{
    private const string TABLE = 'tx_nrrepurpose_domain_model_artifact';

    public function __construct(
        private ConnectionPool $connectionPool,
        private SocialPublisherInterface $publisher,
        private SourceReviewPolicy $sourceReviewPolicy,
        private SourceReviewPointer $sourceReviewPointer,
    ) {}

    public function publishDue(int $now): PublishReport
    {
        $due = $this->dueRows($now);
        if (!$this->publisher->isConfigured()) {
            return new PublishReport(count($due), 0, 0, false);
        }

        $published = 0;
        $failed    = 0;
        foreach ($due as $row) {
            if (!$this->claim($row)) {
                continue;
            }

            if (!$this->sourceReviewAllowsPublishing($row)) {
                $this->mark(
                    ScalarCast::int($row['uid']),
                    [
                        'publish_status' => PublishStatus::Failed->value,
                        'publish_error'  => 'Publication blocked: source review is missing, unaccepted or no longer matches the text and original source.',
                    ],
                );
                ++$failed;
                continue;
            }

            $metadata = json_decode(ScalarCast::string($row['metadata']), true);
            $post     = new SocialPost(
                ScalarCast::int($row['uid']),
                ScalarCast::int($row['job']),
                ScalarCast::string($row['variant']),
                ScalarCast::string($row['script_text']),
                ScalarCast::int($row['publish_at']),
                // Published with the post: the job keeps the user name and password for
                // the fetch, the channel gets the page's address without them. The source
                // value of a page job is a uid; its public URL has a column of its own.
                SourceUrlRedactor::withoutCredentials($row['source_url'] === '' || $row['source_url'] === null ? ScalarCast::string($row['source_value']) : ScalarCast::string($row['source_url'])),
                is_array($metadata) && is_array($metadata['aiLabel'] ?? null) ? $metadata['aiLabel'] : [],
            );

            try {
                $this->publisher->publish($post);
                $this->mark($post->artifactUid, ['publish_status' => PublishStatus::Published->value, 'published_at' => $now, 'publish_error' => '']);
                ++$published;
            } catch (SocialPublishException $e) {
                $this->mark($post->artifactUid, ['publish_status' => PublishStatus::Failed->value, 'publish_error' => $e->getMessage()]);
                ++$failed;
            }
        }

        return new PublishReport(count($due), $published, $failed, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dueRows(int $now): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $expr         = $queryBuilder->expr();

        return $queryBuilder->select(
            'a.uid',
            'a.job',
            'a.variant',
            'a.script_text',
            'a.publish_at',
            'a.metadata',
            'a.source_review',
            'j.source_value',
            'j.source_url',
        )
            ->from(self::TABLE, 'a')
            ->join('a', 'tx_nrrepurpose_domain_model_job', 'j', $expr->eq('j.uid', $queryBuilder->quoteIdentifier('a.job')))
            ->where(
                $expr->eq('a.type', $queryBuilder->createNamedParameter(ArtifactType::SocialPost->value)),
                $expr->eq('a.status', $queryBuilder->createNamedParameter(ArtifactStatus::Done->value)),
                $expr->eq('a.review_status', $queryBuilder->createNamedParameter(ReviewStatus::Approved->value)),
                $expr->eq('a.publish_status', $queryBuilder->createNamedParameter(PublishStatus::Scheduled->value)),
                $expr->gt('a.publish_at', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $expr->lte('a.publish_at', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
            )
            ->orderBy('a.publish_at')
            ->addOrderBy('a.uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Claim only the text and review snapshot that was read. A changed post is
     * left for the next run; an old accepted assessment cannot authorize new copy.
     *
     * @param array<string, mixed> $row
     */
    private function claim(array $row): bool
    {
        return $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                ['publish_status' => PublishStatus::Publishing->value],
                [
                    'uid'            => ScalarCast::int($row['uid']),
                    'status'         => ArtifactStatus::Done->value,
                    'publish_status' => PublishStatus::Scheduled->value,
                    'review_status'  => ReviewStatus::Approved->value,
                    'script_text'    => $row['script_text'],
                    'source_review'  => $row['source_review'],
                    'metadata'       => $row['metadata'],
                    'publish_at'     => $row['publish_at'],
                ],
            ) === 1;
    }

    /**
     * @param array<string, int|string> $fields
     */
    private function mark(int $artifactUid, array $fields): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, $fields, ['uid' => $artifactUid]);
    }

    /** @param array<string, mixed> $row */
    private function sourceReviewAllowsPublishing(array $row): bool
    {
        try {
            // SQL text equality may use a case-insensitive collation. Compare
            // the claimed bytes before authorizing the snapshot to be sent.
            $current = $this->connectionPool
                ->getConnectionForTable(self::TABLE)
                ->select(
                    [
                        'script_text',
                        'source_review',
                        'metadata',
                        'publish_at',
                        'status',
                        'review_status',
                        'publish_status',
                    ],
                    self::TABLE,
                    ['uid' => ScalarCast::int($row['uid'])],
                )
                ->fetchAssociative();
            if (!is_array($current) || $current['status'] !== ArtifactStatus::Done->value || $current['review_status'] !== ReviewStatus::Approved->value || $current['publish_status'] !== PublishStatus::Publishing->value) {
                return false;
            }

            foreach (['script_text', 'source_review', 'metadata', 'publish_at'] as $field) {
                if (ScalarCast::string($current[$field]) !== ScalarCast::string($row[$field])) {
                    return false;
                }
            }

            $job = $this->connectionPool
                ->getConnectionForTable('tx_nrrepurpose_domain_model_job')
                ->select(
                    ['*'],
                    'tx_nrrepurpose_domain_model_job',
                    ['uid' => ScalarCast::int($row['job'])],
                )
                ->fetchAssociative();
            if (!is_array($job)) {
                return false;
            }

            foreach (['source_value', 'source_url'] as $field) {
                if (ScalarCast::string($job[$field]) !== ScalarCast::string($row[$field])) {
                    return false;
                }
            }

            $review = SourceReviewResult::fromJson(
                ScalarCast::string($row['source_review']),
            )->toArray();
            $brief = SourceReviewResult::fromJson(
                ScalarCast::string($job['source_review']),
            )->toArray();

            return $this->sourceReviewPolicy->allowsArtifact(
                $review,
                ScalarCast::string($row['script_text']),
                $brief,
            ) && $this->sourceReviewPolicy->allowsSource(
                $brief,
                $this->sourceReviewPointer->fingerprint($job),
                $review,
            );
        } catch (Throwable) {
            // An unavailable policy cannot authorize publication. The caller
            // records a fixed failure and releases the publishing state.
            return false;
        }
    }
}
