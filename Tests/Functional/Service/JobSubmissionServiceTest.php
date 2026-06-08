<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Service\JobSubmissionService;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

final class JobSubmissionServiceTest extends AbstractFunctionalTestCase
{
    public function testSubmitPersistsJobAndDispatchesMessage(): void
    {
        $dispatched = [];
        $bus = new class($dispatched) implements MessageBusInterface {
            /** @param array<int, object> $sink */
            public function __construct(private array &$sink) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sink[] = $message;

                return new Envelope($message);
            }
        };

        $service = new JobSubmissionService(
            $this->get(JobRepository::class),
            $this->get(PersistenceManagerInterface::class),
            $bus,
        );

        $job = new Job();
        $job->setSourceValue('https://example.com/');
        $uid = $service->submit($job, 1);

        self::assertGreaterThan(0, $uid);
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(GenerateArtifactsMessage::class, $dispatched[0]);
        self::assertSame($uid, $dispatched[0]->jobUid);
    }
}
