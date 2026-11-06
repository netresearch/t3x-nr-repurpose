<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Persistence;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Model\Artifact;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\ArtifactRepository;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

final class SourceReviewPersistenceTest extends AbstractFunctionalTestCase
{
    public function testJobAndArtifactStoreIndependentAssessmentsAndRetryKeepsEnforcement(): void
    {
        $pool       = GeneralUtility::makeInstance(ConnectionPool::class);
        $connection = $pool->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $connection->insert(
            'tx_nrrepurpose_domain_model_job',
            ['pid' => 0, 'status' => 'failed', 'source_type' => 'url'],
        );
        $uid        = (int) $connection->lastInsertId();
        $repository = $this->get(JobProcessingRepository::class);
        $brief      = ['status' => 'flagged', 'mode' => 'enforce'];
        $repository->setSourceReview($uid, $brief);
        $artifact = $repository->insertArtifact(
            $uid,
            ArtifactType::SocialPost,
            'linkedin',
            0,
            ArtifactStatus::Pending,
        );
        $artifactReview = '{"status":"error","mode":"enforce"}';
        $repository->updateArtifact(
            $artifact,
            ['source_review' => $artifactReview],
        );
        self::assertTrue($repository->requeueFailed($uid));
        self::assertSame(
            $brief,
            json_decode(
                $repository->findRow($uid)['source_review'],
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame(
            $artifactReview,
            $pool
                ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
                ->select(
                    ['source_review'],
                    'tx_nrrepurpose_domain_model_artifact',
                    ['uid' => $artifact],
                )
                ->fetchOne(),
        );
    }

    public function testJobRoundTripsStoredReviewJsonThroughExtbase(): void
    {
        $repository = $this->get(JobRepository::class);
        $manager    = $this->get(PersistenceManagerInterface::class);
        $json       = json_encode(
            [
                'status'        => 'passed',
                'mode'          => 'enforce',
                'candidateHash' => hash('sha256', 'The source text.'),
            ],
            JSON_THROW_ON_ERROR,
        );
        $entity = new Job();
        $entity->setSourceValue('https://example.org/original');
        $entity->_setProperty('sourceReview', $json);

        $repository->add($entity);
        $manager->persistAll();
        $uid = $entity->getUid();
        $manager->clearState();
        $loaded = $repository->findByUid($uid);
        self::assertInstanceOf(Job::class, $loaded);
        self::assertNotSame($entity, $loaded);
        self::assertSame($json, $loaded->getSourceReview());
        self::assertSame(
            'passed',
            $loaded->getSourceReviewResult()->getStatus(),
        );
    }

    public function testArtifactRoundTripsStoredReviewJsonThroughExtbase(): void
    {
        $repository = $this->get(ArtifactRepository::class);
        $manager    = $this->get(PersistenceManagerInterface::class);
        $json       = json_encode(
            [
                'status'        => 'passed',
                'mode'          => 'enforce',
                'candidateHash' => hash('sha256', 'The source text.'),
            ],
            JSON_THROW_ON_ERROR,
        );
        $entity = new Artifact();
        $entity->_setProperty('type', 'social_post');
        $entity->_setProperty('scriptText', 'The source text.');
        $entity->_setProperty('sourceReview', $json);

        $repository->add($entity);
        $manager->persistAll();
        $uid = $entity->getUid();
        $manager->clearState();
        $loaded = $repository->findByUid($uid);
        self::assertInstanceOf(Artifact::class, $loaded);
        self::assertNotSame($entity, $loaded);
        self::assertSame($json, $loaded->getSourceReview());
        self::assertSame(
            'passed',
            $loaded->getSourceReviewResult()->getStatus(),
        );
    }

    private function seedReviewSnapshot(?string $snapshot): int
    {
        $connection = $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $connection->insert(
            'tx_nrrepurpose_domain_model_job',
            [
                'source_type'   => 'url',
                'source_review' => $snapshot,
                'tstamp'        => time(),
            ],
        );

        return (int) $connection->lastInsertId();
    }

    public function testReviewCompareAndSetDistinguishesSqlNullFromEmptyString(): void
    {
        $uid        = $this->seedReviewSnapshot(null);
        $repository = $this->get(JobProcessingRepository::class);
        $review     = ['mode' => 'enforce', 'status' => 'passed'];

        self::assertFalse(
            $repository->compareAndSetSourceReview($uid, $review, ''),
        );
        self::assertNull($repository->findRow($uid)['source_review']);
        self::assertTrue(
            $repository->compareAndSetSourceReview($uid, $review, null),
        );
        self::assertSame(
            $review,
            json_decode($repository->findRow($uid)['source_review'], true),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function olderReviewModes(): iterable
    {
        yield 'off' => ['off'];
        yield 'observe' => ['observe'];
    }

    #[DataProvider('olderReviewModes')]
    public function testReviewCompareAndSetPreservesAConcurrentEnforcedVerdict(
        string $olderMode,
    ): void {
        $previous   = '{"mode":"observe","status":"passed"}';
        $uid        = $this->seedReviewSnapshot($previous);
        $repository = $this->get(JobProcessingRepository::class);
        $repository->setSourceReview(
            $uid,
            [
                'mode'   => 'enforce',
                'status' => 'flagged',
                'model'  => 'newer reviewer',
            ],
        );
        $current = $repository->findRow($uid);

        self::assertFalse(
            $repository->compareAndSetSourceReview(
                $uid,
                ['mode' => $olderMode, 'status' => 'not_run'],
                $previous,
            ),
        );
        self::assertSame(
            $current['source_review'],
            $repository->findRow($uid)['source_review'],
        );
        self::assertSame(
            $current['tstamp'],
            $repository->findRow($uid)['tstamp'],
        );
    }

    public function testReviewCompareAndSetUsesByteExactSnapshotOnEveryDatabase(): void
    {
        $current    = '{"mode":"observe","marker":"New Claim"}';
        $uid        = $this->seedReviewSnapshot($current);
        $repository = $this->get(JobProcessingRepository::class);

        self::assertFalse(
            $repository->compareAndSetSourceReview(
                $uid,
                ['mode' => 'off'],
                '{"mode":"observe","marker":"new claim"}',
            ),
        );
        self::assertSame($current, $repository->findRow($uid)['source_review']);
    }

    public function testReviewCompareAndSetAcceptsAnUnchangedSnapshotButNeverAMissingJob(): void
    {
        $review     = ['mode' => 'off', 'status' => 'not_run'];
        $json       = json_encode($review, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $uid        = $this->seedReviewSnapshot($json);
        $repository = $this->get(JobProcessingRepository::class);

        self::assertTrue(
            $repository->compareAndSetSourceReview($uid, $review, $json),
        );
        self::assertSame($json, $repository->findRow($uid)['source_review']);
        self::assertFalse(
            $repository->compareAndSetSourceReview($uid + 1, $review, null),
        );
    }
}
