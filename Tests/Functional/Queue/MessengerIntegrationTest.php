<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Queue;

use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Queue\Handler\GenerateArtifactsHandler;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use Psr\Log\NullLogger;
use RuntimeException;
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
            'pid'   => 0, 'source_type' => 'url', 'source_value' => 'https://example.com/',
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

    /**
     * What reaches the handler escaped the orchestrator's own step handling — a generator
     * that threw (the Schaubild's diagram completion is not caught in the generator), a
     * database error. Its message can be the text model's answer, a path or SQL, and the
     * job's error_message is shown to every module user: the row gets a fixed text and the
     * exception goes to the log.
     */
    public function testHandlerCatchesOrchestratorCrashAndMarksJobFailedWithAFixedReason(): void
    {
        $jobUid = $this->seedJob();
        $jobs   = $this->get(JobProcessingRepository::class);
        $logger = new RecordingLogger();
        $cause  = new OrchestratorCrashException('worker exploded: Provider answered 401 for https://api.example.test/v1 with key sk-secret');

        $orchestrator = new readonly class ($cause) implements GenerationOrchestratorInterface {
            public function __construct(private OrchestratorCrashException $cause) {}

            public function process(int $jobUid): void
            {
                throw $this->cause;
            }
        };

        $handler = new GenerateArtifactsHandler($orchestrator, $jobs, $logger);
        $handler(new GenerateArtifactsMessage($jobUid));

        $row = $jobs->findRow($jobUid);
        self::assertSame('failed', $row['status']);
        self::assertSame('Generation failed', $row['error_message']);
        self::assertContains($cause, array_map(static fn (array $record): mixed => $record['context']['exception'] ?? null, $logger->records));
    }
}

/**
 * Dedicated exception for the simulated orchestrator crash (php:S112 — no generic
 * RuntimeException throws). The handler's catch contract is Throwable, so the
 * concrete type is irrelevant to the behaviour under test.
 */
final class OrchestratorCrashException extends RuntimeException {}
