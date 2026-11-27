<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Queue\Handler;

use Netresearch\NrRepurpose\Exception\RunClaimHeldException;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Service\FailureClassifier;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Throwable;

/**
 * v14.3 Core has NO retry/failure transport — so a hard failure is caught here and the job
 * is marked failed (no rethrow), otherwise the message would be lost with no record.
 *
 * A crash after the run claimed the job (a generator that threw, a database error) is failed
 * by the orchestrator itself, bound to the claim (#238). What reaches this catch failed
 * before the claim, for example reading the job row, so its message can be a path or SQL.
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
        private MessageBusInterface $bus,
    ) {}

    public function __invoke(GenerateArtifactsMessage $message): void
    {
        try {
            $this->orchestrator->process($message->jobUid);
        } catch (RunClaimHeldException) {
            // Another run holds the unfinished job (#238). This delivery is acknowledged, so the
            // message comes back after one lease. Then the other run has finished and the
            // message does nothing; or the other run's worker is gone, its claim has expired
            // and the message takes the job over; or the claim is still live and the message
            // is sent again. A live run that lasts long sees one such message per lease.
            // Same transport pin as JobSubmissionService::dispatch().
            $this->bus->dispatch(
                new GenerateArtifactsMessage($message->jobUid),
                [
                    new TransportNamesStamp(['doctrine']),
                    new DelayStamp(JobProcessingRepository::RUN_LEASE_SECONDS * 1000),
                ],
            );
        } catch (Throwable $e) {
            $this->logger->error('Generation job crashed', ['job' => $message->jobUid, 'exception' => $e]);
            $this->jobs->markFailed($message->jobUid, 'Generation failed', FailureClassifier::reasonFor($e));
        }
    }
}
