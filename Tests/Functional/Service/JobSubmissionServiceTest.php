<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Closure;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\FailureReason;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceReport;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Service\JobSubmissionService;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

final class JobSubmissionServiceTest extends AbstractFunctionalTestCase
{
    public function testSubmitPersistsJobAndDispatchesMessage(): void
    {
        $dispatched = [];
        $bus        = new class ($dispatched) implements MessageBusInterface {
            /** @param array<int, array{message: object, stamps: array<int, object>}> $sink */
            public function __construct(private array &$sink) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sink[] = ['message' => $message, 'stamps' => $stamps];

                return new Envelope($message);
            }
        };

        $service = new JobSubmissionService(
            $this->get(JobRepository::class),
            $this->get(PersistenceManagerInterface::class),
            $bus,
            $this->get(JobProcessingRepository::class),
        );

        $job = new Job();
        $job->setSourceValue('https://example.com/');

        $uid = $service->submit($job, 1);

        self::assertGreaterThan(0, $uid);
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(GenerateArtifactsMessage::class, $dispatched[0]['message']);
        self::assertSame($uid, $dispatched[0]['message']->jobUid);

        // The transport MUST be pinned: TYPO3's TransportLocator sends to every
        // matching routing entry (the '*' => sync default included), so without
        // this stamp the generation would ALSO run synchronously in the web
        // request — twice per job, racing each other.
        $stamps = array_filter(
            $dispatched[0]['stamps'],
            static fn (object $stamp): bool => $stamp instanceof TransportNamesStamp,
        );
        self::assertCount(1, $stamps);
        self::assertSame(['doctrine'], array_values($stamps)[0]->getTransportNames());
    }

    /** Two clicks, one run: what stops the second is the status, in the statement itself. */
    public function testAFailedJobRunsAgainOnceAndKeepsNoOldArtifact(): void
    {
        $dispatched = [];
        $bus        = new class ($dispatched) implements MessageBusInterface {
            /** @param list<object> $sink */
            public function __construct(private array &$sink) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sink[] = $message;

                return new Envelope($message);
            }
        };
        $jobs    = $this->get(JobProcessingRepository::class);
        $service = new JobSubmissionService($this->get(JobRepository::class), $this->get(PersistenceManagerInterface::class), $bus, $jobs);

        $job = new Job();
        $job->setSourceValue('https://example.com/');

        $uid        = $service->submit($job, 1);
        $dispatched = [];
        $jobs->insertArtifact($uid, ArtifactType::Faq, 'default', 0, ArtifactStatus::Failed, 'FAQ failed');
        $jobs->markFailed($uid, 'Analysis failed', FailureReason::AnswerUnusable);
        $jobs->setSourceReport($uid, new SourceReport(pagesUsed: 17));

        self::assertTrue($service->retry($uid));
        self::assertFalse($service->retry($uid), 'The job is queued now');

        self::assertCount(1, $dispatched);
        $row = $jobs->findRow($uid) ?? [];
        self::assertSame(['queued', '', '', 0, ''], [$row['status'], $row['error_message'], $row['failure_reason'], (int) $row['progress'], $row['source_report']]);
        self::assertSame(0, GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->count('uid', 'tx_nrrepurpose_domain_model_artifact', ['job' => $uid]));
    }

    /**
     * A job submitted with the form defaults (an untouched Job) is stored requesting the
     * media artifacts and none of the four text formats.
     */
    public function testAJobWithTheFormDefaultsRequestsNoTextFormat(): void
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message);
            }
        };
        $service = new JobSubmissionService($this->get(JobRepository::class), $this->get(PersistenceManagerInterface::class), $bus, $this->get(JobProcessingRepository::class));

        $job = new Job();
        $job->setSourceValue('https://example.com/');

        $row = $this->get(JobProcessingRepository::class)->findRow($service->submit($job, 1)) ?? [];

        foreach (['want_podcast', 'want_schaubild', 'want_story'] as $column) {
            self::assertSame(1, (int) $row[$column], $column);
        }

        foreach (['want_exec_summary', 'want_faq', 'want_social_post', 'want_newsletter', 'want_slide_deck', 'want_handout', 'want_video'] as $column) {
            self::assertSame(0, (int) $row[$column], $column);
        }
    }

    /** The worker reads the reference as soon as the message is queued. */
    public function testAPickedFileIsAttachedBeforeTheMessageGoesOut(): void
    {
        $references = [];
        $count      = static fn (): int => GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')->count('uid', 'sys_file_reference', ['uid_local' => 42]);
        $bus = new class ($references, $count) implements MessageBusInterface {
            /**
             * @param list<int>      $seen
             * @param Closure(): int $count
             */
            public function __construct(private array &$seen, private readonly Closure $count) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->seen[] = ($this->count)();

                return new Envelope($message);
            }
        };
        $service = new JobSubmissionService($this->get(JobRepository::class), $this->get(PersistenceManagerInterface::class), $bus, $this->get(JobProcessingRepository::class));

        $job = new Job();
        $job->setSourceType('pdf_fal');

        $service->submit($job, 1, 42);

        self::assertSame([1], $references);
    }

    /** The video is made from the story slides, so asking for it switches the story on. */
    public function testAskingForTheVideoSwitchesTheStoryOn(): void
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message);
            }
        };
        $service = new JobSubmissionService($this->get(JobRepository::class), $this->get(PersistenceManagerInterface::class), $bus, $this->get(JobProcessingRepository::class));

        $job = new Job();
        $job->setSourceValue('https://example.com/');
        $job->setWantStory(false);
        $job->setWantVideo(true);

        $row = $this->get(JobProcessingRepository::class)->findRow($service->submit($job, 1)) ?? [];

        self::assertSame([1, 1], [(int) $row['want_story'], (int) $row['want_video']]);
    }
}
