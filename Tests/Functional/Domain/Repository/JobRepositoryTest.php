<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Domain\Repository;

use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

final class JobRepositoryTest extends AbstractFunctionalTestCase
{
    public function testJobRoundTripsThroughExtbasePersistence(): void
    {
        $repo = $this->get(JobRepository::class);
        $pm = $this->get(PersistenceManagerInterface::class);

        $job = new Job();
        $job->setSourceValue('https://example.com/');
        $job->setStatusEnum(JobStatus::Queued);
        $repo->add($job);
        $pm->persistAll();

        $pm->clearState();
        /** @var Job $loaded */
        $loaded = $repo->findByUid($job->getUid());

        self::assertSame('https://example.com/', $loaded->getSourceValue());
        self::assertSame(JobStatus::Queued, $loaded->getStatusEnum());
        self::assertTrue($loaded->isWantPodcast());
    }
}
