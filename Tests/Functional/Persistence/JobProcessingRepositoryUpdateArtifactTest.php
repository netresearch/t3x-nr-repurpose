<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Persistence;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class JobProcessingRepositoryUpdateArtifactTest extends AbstractFunctionalTestCase
{
    private function seedJob(): int
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid' => 0, 'source_type' => 'url', 'source_value' => 'https://example.com/',
            'theme' => 'nr', 'want_podcast' => 1, 'want_schaubild' => 1, 'want_story' => 1, 'status' => 'queued',
        ]);

        return (int) $conn->lastInsertId();
    }

    public function testUpdateArtifactWritesAllProvidedFields(): void
    {
        $jobUid = $this->seedJob();
        $repo = $this->get(JobProcessingRepository::class);

        $artifactUid = $repo->insertArtifact($jobUid, ArtifactType::Podcast, 'default', 0, ArtifactStatus::Pending);

        $repo->updateArtifact($artifactUid, [
            'file_uid' => 42,
            'subtitle_file_uid' => 99,
            'source_html' => '<html>diagram</html>',
            'script_text' => "Host A: hello\nHost B: hi",
            'metadata' => '{"voice":"nova"}',
            'status' => ArtifactStatus::Done->value,
        ]);

        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact');
        $row = $conn->select(['*'], 'tx_nrrepurpose_domain_model_artifact', ['uid' => $artifactUid])
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame(42, (int) $row['file_uid']);
        self::assertSame(99, (int) $row['subtitle_file_uid']);
        self::assertSame('<html>diagram</html>', $row['source_html']);
        self::assertSame("Host A: hello\nHost B: hi", $row['script_text']);
        self::assertSame('{"voice":"nova"}', $row['metadata']);
        self::assertSame('done', $row['status']);
    }

    public function testUpdateArtifactWithEmptyFieldsIsNoOp(): void
    {
        $jobUid = $this->seedJob();
        $repo = $this->get(JobProcessingRepository::class);
        $artifactUid = $repo->insertArtifact($jobUid, ArtifactType::Story, 'default', 0, ArtifactStatus::Pending);

        $repo->updateArtifact($artifactUid, []);

        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact');
        $row = $conn->select(['status'], 'tx_nrrepurpose_domain_model_artifact', ['uid' => $artifactUid])
            ->fetchAssociative();

        self::assertSame('pending', $row['status']);
    }
}
