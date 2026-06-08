<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Queue;

use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Queue\Handler\GenerateArtifactsHandler;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Verifies the Messenger handler logic: __invoke runs the orchestrator for the message's job
 * uid, and a crashing orchestrator is caught and the job marked failed (v14.3 Core has no
 * retry/failure transport, so the handler must not let the message vanish silently).
 *
 * The orchestrator is faked here so no real ingestion/analysis/provider call happens; the
 * full real pipeline is exercised by the end-run, not the functional suite.
 */
final class MessengerIntegrationTest extends AbstractFunctionalTestCase
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

    public function testHandlerRunsTheOrchestratorForTheMessageJobUid(): void
    {
        $jobUid = $this->seedJob();

        $orchestrator = new class implements GenerationOrchestratorInterface {
            public int $processed = 0;

            public function process(int $jobUid): void
            {
                $this->processed = $jobUid;
            }
        };

        $handler = new GenerateArtifactsHandler(
            $orchestrator,
            $this->get(JobProcessingRepository::class),
            new NullLogger(),
        );

        $handler(new GenerateArtifactsMessage($jobUid));

        self::assertSame($jobUid, $orchestrator->processed);
    }

    public function testHandlerCatchesOrchestratorCrashAndMarksJobFailed(): void
    {
        $jobUid = $this->seedJob();
        $jobs = $this->get(JobProcessingRepository::class);

        $orchestrator = new class implements GenerationOrchestratorInterface {
            public function process(int $jobUid): void
            {
                throw new \RuntimeException('worker exploded');
            }
        };

        $handler = new GenerateArtifactsHandler($orchestrator, $jobs, new NullLogger());
        $handler(new GenerateArtifactsMessage($jobUid));

        $row = $jobs->findRow($jobUid);
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('worker exploded', (string) $row['error_message']);
    }
}
