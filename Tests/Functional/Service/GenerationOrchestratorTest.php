<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class GenerationOrchestratorTest extends AbstractFunctionalTestCase
{
    public function testProcessMovesJobToDoneAndCreatesStubArtifact(): void
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid' => 0, 'source_type' => 'url', 'source_value' => 'https://example.com/',
            'theme' => 'nr', 'want_podcast' => 1, 'want_schaubild' => 1, 'want_story' => 1, 'status' => 'queued',
        ]);
        $jobUid = (int)$conn->lastInsertId();

        $this->get(GenerationOrchestratorInterface::class)->process($jobUid);

        $row = $this->get(JobProcessingRepository::class)->findRow($jobUid);
        self::assertSame('done', $row['status']);
        self::assertSame(100, (int)$row['progress']);

        $artifactCount = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->count('uid', 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid, 'status' => 'done']);
        self::assertSame(1, $artifactCount);
    }
}
