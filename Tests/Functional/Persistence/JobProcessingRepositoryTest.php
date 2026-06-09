<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Persistence;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class JobProcessingRepositoryTest extends AbstractFunctionalTestCase
{
    private function seedJob(): int
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid' => 0, 'source_type' => 'url', 'source_value' => 'https://example.com/',
            'theme' => 'nr', 'want_podcast' => 1, 'want_schaubild' => 1, 'want_story' => 1,
            'status' => 'queued',
        ]);
        return (int)$conn->lastInsertId();
    }

    public function testMarkStatusUpdatesRow(): void
    {
        $uid = $this->seedJob();
        $repo = $this->get(JobProcessingRepository::class);

        $repo->markStatus($uid, JobStatus::Generating, 'stub', 50);

        $row = $repo->findRow($uid);
        self::assertSame('generating', $row['status']);
        self::assertSame(50, (int)$row['progress']);
        self::assertSame('stub', $row['current_step']);
    }

    public function testInsertArtifactReturnsUidAndPersists(): void
    {
        $uid = $this->seedJob();
        $repo = $this->get(JobProcessingRepository::class);

        $artifactUid = $repo->insertArtifact($uid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Done);

        self::assertGreaterThan(0, $artifactUid);
        $count = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->count('uid', 'tx_nrrepurpose_domain_model_artifact', ['job' => $uid]);
        self::assertSame(1, $count);
    }

    public function testDeleteArtifactsForJobRemovesOnlyThatJobsRows(): void
    {
        $jobA = $this->seedJob();
        $jobB = $this->seedJob();
        $repo = $this->get(JobProcessingRepository::class);
        // file_uid 0 keeps this DB-only (no FAL files to resolve/delete).
        $repo->insertArtifact($jobA, ArtifactType::Stub, 'default', 0, ArtifactStatus::Done);
        $repo->insertArtifact($jobA, ArtifactType::Stub, 'second', 0, ArtifactStatus::Failed);
        $repo->insertArtifact($jobB, ArtifactType::Stub, 'default', 0, ArtifactStatus::Done);

        $repo->deleteArtifactsForJob($jobA);

        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact');
        self::assertSame(0, $conn->count('uid', 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobA]));
        self::assertSame(1, $conn->count('uid', 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobB]));
    }
}
