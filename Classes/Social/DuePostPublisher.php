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
    private const TABLE = 'tx_nrrepurpose_domain_model_artifact';

    public function __construct(
        private ConnectionPool $connectionPool,
        private SocialPublisherInterface $publisher,
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
            if (!$this->claim((int) $row['uid'])) {
                continue;
            }

            $metadata = json_decode((string) $row['metadata'], true);
            $post     = new SocialPost(
                (int) $row['uid'],
                (int) $row['job'],
                (string) $row['variant'],
                (string) $row['script_text'],
                (int) $row['publish_at'],
                (string) $row['source_value'],
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

        return $queryBuilder
            ->select('a.uid', 'a.job', 'a.variant', 'a.script_text', 'a.publish_at', 'a.metadata', 'j.source_value')
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

    /** scheduled → publishing, only if the row is still scheduled and still approved. */
    private function claim(int $artifactUid): bool
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['publish_status' => PublishStatus::Publishing->value],
            [
                'uid'            => $artifactUid,
                'publish_status' => PublishStatus::Scheduled->value,
                'review_status'  => ReviewStatus::Approved->value,
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
}
