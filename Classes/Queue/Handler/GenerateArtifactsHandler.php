<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Queue\Handler;

use Throwable;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * v14.3 Core has NO retry/failure transport — so a hard failure is caught here and the job
 * is marked failed (no rethrow), otherwise the message would be lost with no record.
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
            $this->logger->error('Generation job crashed', ['job' => $message->jobUid, 'exception' => $e->getMessage()]);
            $this->jobs->markFailed($message->jobUid, $e->getMessage());
        }
    }
}
