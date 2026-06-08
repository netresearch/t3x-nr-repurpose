<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Queue;

use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Proves the full Messenger loop: dispatching through the real bus routes to the
 * (sync, in tests) transport and invokes the #[AsMessageHandler] handler, which runs
 * the orchestrator to completion. This is the integration the unit/orchestrator tests
 * cannot cover (handler registration + bus wiring).
 */
final class MessengerIntegrationTest extends AbstractFunctionalTestCase
{
    public function testDispatchingMessageRunsHandlerToCompletion(): void
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid' => 0, 'source_type' => 'url', 'source_value' => 'https://example.com/',
            'theme' => 'nr', 'want_podcast' => 1, 'want_schaubild' => 1, 'want_story' => 1, 'status' => 'queued',
        ]);
        $jobUid = (int)$conn->lastInsertId();

        // Default test routing is synchronous, so dispatch invokes the handler inline.
        $this->get(MessageBusInterface::class)->dispatch(new GenerateArtifactsMessage($jobUid));

        $row = $this->get(JobProcessingRepository::class)->findRow($jobUid);
        self::assertSame('done', $row['status'], 'handler ran the orchestrator to completion');

        $doneArtifacts = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->count('uid', 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid, 'status' => 'done']);
        self::assertSame(1, $doneArtifacts);
    }
}
