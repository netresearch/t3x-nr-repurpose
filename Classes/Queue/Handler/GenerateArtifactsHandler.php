<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Queue\Handler;

use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Exception\RunClaimHeldException;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Service\FailureClassifier;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Netresearch\NrRepurpose\Service\ProviderDispatchLimitCheck;
use Netresearch\NrRepurpose\Utility\ScalarCast;
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
    private const int DISPATCH_ATTEMPTS = 3;

    /** Seconds after the claim's expiry the message comes back, so it finds the claim expired. */
    public const int REDELIVERY_MARGIN_SECONDS = 30;

    /** The shortest delay, so a claim about to expire does not bounce the message right back. */
    public const int REDELIVERY_FLOOR_SECONDS = 60;

    public function __construct(
        private GenerationOrchestratorInterface $orchestrator,
        private JobProcessingRepository $jobs,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
        private ?ProviderDispatchLimitCheck $providerLimit = null,
    ) {}

    public function __invoke(GenerateArtifactsMessage $message): void
    {
        // Before the worker's first job: an nr-llm call longer than the lease (#238).
        $this->providerLimit?->warnOnce();

        try {
            $this->orchestrator->process($message->jobUid);
        } catch (RunClaimHeldException) {
            // Another run holds the unfinished job (#238). This delivery is acknowledged, so the
            // message comes back once the claim would have expired. Then the other run has finished and the
            // message does nothing; or the other run's worker is gone, its claim has expired
            // and the message takes the job over; or the claim is still live and the message
            // is sent again. A live run that lasts long sees one such message per lease.
            // Same transport pin as JobSubmissionService::dispatch().
            $this->dispatchAgain($message->jobUid);
        } catch (Throwable $e) {
            $this->logger->error('Generation job crashed', ['job' => $message->jobUid, 'exception' => $e]);
            $this->jobs->markFailed($message->jobUid, 'Generation failed', FailureClassifier::reasonFor($e));
        }
    }

    /**
     * The message that comes back once the other run's claim would have expired, unless that
     * run renews it. A failed send is tried twice more, then
     * rethrown: TYPO3 Core has no retry strategy, so Messenger rejects the delivery, which
     * deletes it from the doctrine queue, and the job keeps its status until it is queued
     * again. Rethrowing at least leaves the failure in the worker log, and a rejection that
     * fails as well (the database the send failed on) leaves the message in the queue for
     * the next redelivery.
     */
    private function dispatchAgain(int $jobUid): void
    {
        $delay = $this->redeliveryDelay($jobUid);
        for ($attempt = 1;; ++$attempt) {
            try {
                $this->bus->dispatch(
                    new GenerateArtifactsMessage($jobUid),
                    [
                        new TransportNamesStamp(['doctrine']),
                        new DelayStamp($delay * 1000),
                    ],
                );

                return;
            } catch (Throwable $e) {
                if ($attempt >= self::DISPATCH_ATTEMPTS) {
                    $this->logger->critical('A held job could not be queued again', ['job' => $jobUid, 'exception' => $e]);

                    throw $e;
                }
            }
        }
    }

    /**
     * Seconds until the claim the delivery found would expire, plus a margin; at least the floor,
     * at most one lease. A live run renews its claim, so its message comes back a lease later at
     * most. Without a readable claim, one lease: this runs in the handler's catch, where an
     * exception would make Messenger reject the delivery and delete the message.
     */
    private function redeliveryDelay(int $jobUid): int
    {
        try {
            $claimedAt = ScalarCast::int($this->jobs->findRow($jobUid)['run_claimed_at'] ?? 0);
        } catch (Throwable $e) {
            $this->logger->warning('The claim of a held job could not be read; it is sent again after one lease', ['job' => $jobUid, 'exception' => $e]);

            return Job::RUN_LEASE_SECONDS + self::REDELIVERY_MARGIN_SECONDS;
        }

        $remaining = min(Job::RUN_LEASE_SECONDS, $claimedAt + Job::RUN_LEASE_SECONDS - time());

        return max(self::REDELIVERY_FLOOR_SECONDS, $remaining + self::REDELIVERY_MARGIN_SECONDS);
    }
}
