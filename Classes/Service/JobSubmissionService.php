<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Persists a new Job (Extbase, request context) and dispatches the generation message.
 * Extracted from the controller so the create flow is testable without an Extbase request.
 */
final class JobSubmissionService
{
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly MessageBusInterface $bus,
    ) {}

    /** @return int the new job uid */
    public function submit(Job $job, int $beUser): int
    {
        $job->setBeUser($beUser);
        $this->jobRepository->add($job);
        $this->persistenceManager->persistAll(); // ensure uid before dispatch

        $jobUid = $job->getUid();
        $this->bus->dispatch(new GenerateArtifactsMessage($jobUid));

        return $jobUid;
    }
}
