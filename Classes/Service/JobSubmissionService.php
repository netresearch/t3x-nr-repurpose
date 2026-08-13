<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use RuntimeException;

/**
 * Persists a new Job (Extbase, request context) and dispatches the generation message.
 * Extracted from the controller so the create flow is testable without an Extbase request.
 */
final readonly class JobSubmissionService
{
    public function __construct(
        private JobRepository $jobRepository,
        private PersistenceManagerInterface $persistenceManager,
        private MessageBusInterface $bus,
    ) {}

    /** @return int the new job uid */
    public function submit(Job $job, int $beUser): int
    {
        $job->setBeUser($beUser);
        $this->jobRepository->add($job);
        $this->persistenceManager->persistAll(); // ensure uid before dispatch

        // persistAll() above assigns the uid, but getUid() stays nullable in the
        // Extbase base class, so the value has to be narrowed before it is put
        // into a message that declares int.
        $jobUid = $job->getUid();
        if ($jobUid === null) {
            throw new RuntimeException('The job has no uid after persisting', 1755000202);
        }

        // Pin the transport explicitly: TYPO3's TransportLocator (unlike Symfony's
        // SendersLocator) does NOT treat the '*' routing entry as a fallback — it
        // yields a sender for EVERY matching routing type. With core's default
        // routing ('*' => 'default' = SyncTransport) plus a doctrine entry for this
        // message, every dispatch would run the whole generation synchronously
        // inside the web request (blocking it for minutes on a PHP-FPM container
        // that lacks the generation binaries) AND queue it for the worker — two
        // racing executions per job. TransportNamesStamp takes the locator's
        // exclusive first branch, so the message goes to the doctrine queue only.
        $this->bus->dispatch(
            new GenerateArtifactsMessage($jobUid),
            [new TransportNamesStamp(['doctrine'])],
        );

        return $jobUid;
    }
}
