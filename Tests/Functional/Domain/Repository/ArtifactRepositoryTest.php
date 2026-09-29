<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Domain\Repository;

use Netresearch\NrRepurpose\Domain\Repository\ArtifactRepository;
use Netresearch\NrRepurpose\Domain\ValueObject\ArtifactTypeSummary;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(ArtifactRepository::class)]
final class ArtifactRepositoryTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function typeSummariesFoldEachJobsArtifactsLikeTheModel(): void
    {
        // Enum order (podcast, schaubild, story); any failure wins, then any pending.
        $this->insert(10, 'podcast', 'done');
        $this->insert(10, 'story', 'done');
        $this->insert(10, 'story', 'failed');
        $this->insert(10, 'schaubild', 'pending');
        $this->insert(10, 'schaubild', 'done');
        // A legacy status is skipped, as Job::getArtifactTypeSummaries() skips it.
        $this->insert(12, 'podcast', 'legacy');
        // Another job's artifacts stay out of the result.
        $this->insert(99, 'faq', 'done');

        $summaries = $this->get(ArtifactRepository::class)->findTypeSummariesByJobs([10, 11, 12]);

        self::assertSame([10, 11, 12], array_keys($summaries));
        self::assertSame(
            [['podcast', 'done'], ['schaubild', 'pending'], ['story', 'failed']],
            array_map(static fn (ArtifactTypeSummary $summary): array => [$summary->type->value, $summary->status->value], $summaries[10]),
        );
        self::assertSame([], $summaries[11]);
        self::assertSame([], $summaries[12]);
    }

    #[Test]
    public function noJobsMeansNoQueryResult(): void
    {
        self::assertSame([], $this->get(ArtifactRepository::class)->findTypeSummariesByJobs([]));
    }

    private function insert(int $job, string $type, string $status): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->insert('tx_nrrepurpose_domain_model_artifact', ['pid' => 0, 'job' => $job, 'type' => $type, 'status' => $status]);
    }
}
