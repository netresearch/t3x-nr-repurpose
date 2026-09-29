<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Repository;

use Netresearch\NrRepurpose\Domain\Model\Artifact;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\ValueObject\ArtifactTypeSummary;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * @extends Repository<Artifact>
 */
class ArtifactRepository extends Repository
{
    public const TABLE = 'tx_nrrepurpose_domain_model_artifact';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    /**
     * The artifact-type summaries of several jobs from one grouped query, for the job list:
     * mapping each job's artifacts as objects would cost one query per row. The fold is
     * Job::summarizeArtifactStatuses(), the same one Job::getArtifactTypeSummaries() uses.
     * A job without artifacts maps to an empty list.
     *
     * Plain DBAL rather than an Extbase query: the artifacts belong to the job whatever page
     * they are stored on, so storage-page filtering must not apply.
     *
     * @param list<int> $jobUids
     *
     * @return array<int, list<ArtifactTypeSummary>>
     */
    public function findTypeSummariesByJobs(array $jobUids): array
    {
        $summaries = array_fill_keys($jobUids, []);
        if ($jobUids === []) {
            return $summaries;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows         = $queryBuilder
            ->select('job', 'type', 'status')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->in('job', $queryBuilder->createNamedParameter($jobUids, Connection::PARAM_INT_ARRAY)))
            ->groupBy('job', 'type', 'status')
            ->executeQuery()
            ->fetchAllAssociative();

        $pairsByJob = [];
        foreach ($rows as $row) {
            $pairsByJob[(int) $row['job']][] = [(string) $row['type'], (string) $row['status']];
        }

        foreach ($pairsByJob as $job => $pairs) {
            $summaries[$job] = Job::summarizeArtifactStatuses($pairs);
        }

        return $summaries;
    }
}
