<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Queue\Handler;

use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * v14.3 Core has NO retry/failure transport — so a hard failure is caught here and the job
 * is marked failed (no rethrow), otherwise the message would be lost with no record.
 *
 * What reaches this catch escaped the orchestrator's own step handling (a generator that
 * threw, a database error), so its message can be the text model's answer, a path or SQL.
 * The job's error_message is shown to every module user: it gets a fixed text, the
 * exception goes to the log.
 */
#[AsMessageHandler]
final readonly class GenerateArtifactsHandler
{
    public function __construct(
        private GenerationOrchestratorInterface $orchestrator,
        private JobProcessingRepository $jobs,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(GenerateArtifactsMessage $message): void
    {
        try {
            $this->orchestrator->process($message->jobUid);
        } catch (Throwable $e) {
            $this->logger->error('Generation job crashed', ['job' => $message->jobUid, 'exception' => $e]);
            $this->jobs->markFailed($message->jobUid, 'Generation failed');
        }
    }
}
