<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Closure;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Service\Decision\DecisionServiceInterface;
use Netresearch\NrRepurpose\Command\GenerateCommand;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\FailureReason;
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Exception\RunClaimHeldException;
use Netresearch\NrRepurpose\Exception\RunOwnershipLostException;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Generator\StoryGenerator;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionService;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\PromptSnippetResolver;
use Netresearch\NrRepurpose\Pipeline\RunClaimRenewalMiddleware;
use Netresearch\NrRepurpose\Provenance\AiLabelSettingsFactory;
use Netresearch\NrRepurpose\Queue\Handler\GenerateArtifactsHandler;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolver;
use Netresearch\NrRepurpose\Service\GenerationOrchestrator;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Netresearch\NrRepurpose\Service\RunOutcome;
use Netresearch\NrRepurpose\SourceReview\SourceReviewGate;
use Netresearch\NrRepurpose\SourceReview\SourceReviewPointer;
use Netresearch\NrRepurpose\SourceReview\SourceReviewPolicy;
use Netresearch\NrRepurpose\SourceReview\SourceReviewService;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Functional\Fixtures\ClaimTestGenerator;
use Netresearch\NrRepurpose\Tests\Functional\Fixtures\FailingMessageBus;
use Netresearch\NrRepurpose\Tests\Functional\Fixtures\RowLosingGenerator;
use Netresearch\NrRepurpose\Tests\Functional\Fixtures\SimulatedFailure;
use Netresearch\NrRepurpose\Tests\Functional\Fixtures\TakeoverDuringGenerationGenerator;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingMessageBus;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\SourceReviewFixture;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Two runs of one job (#238): the transport hands a message out again while its first
 * delivery still runs, or after the worker running it died. Each run gets its own repository
 * instance, as two worker processes have.
 */
final class GenerationRunClaimTest extends AbstractFunctionalTestCase
{
    private const string JOB_TABLE = 'tx_nrrepurpose_domain_model_job';

    private const string ARTIFACT_TABLE = 'tx_nrrepurpose_domain_model_artifact';

    /** The stalled-jobs data provider's idle time for a job whose tstamp is 0. */
    private const int NEVER_WRITTEN = -1;

    /** A second delivery while the first run holds a live claim starts nothing and writes nothing. */
    public function testASecondRunIsBlockedWhileTheFirstHoldsALiveClaim(): void
    {
        $uid   = $this->seedJob();
        $first = $this->repository();
        self::assertTrue($first->claimRun($uid));
        $first->markStatus($uid, JobStatus::Generating, 'generating', 40);
        $before = $this->row($uid);

        $second    = $this->repository();
        $generator = new ClaimTestGenerator($second);
        try {
            $this->orchestrator($second, [$generator])->process($uid);
            self::fail('The second run must not pass the claim');
        } catch (RunClaimHeldException) {
            // Expected: the handler turns this into a delivery after the lease.
        }

        self::assertSame(0, $generator->runs);
        self::assertSame($before, $this->row($uid));
    }

    /**
     * A run whose claim another run took over stops at its next write: neither its artifact
     * nor its progress nor a final status reaches the job.
     */
    public function testARunThatLostItsClaimWritesNeitherStatusNorArtifacts(): void
    {
        $uid       = $this->seedJob();
        $stale     = $this->repository();
        $takeover  = $this->repository();
        $generator = new ClaimTestGenerator($stale, function () use ($stale, $takeover, $uid): void {
            // The first run stalls beyond the lease; a redelivery takes the job over.
            $this->backdateClaim($uid);
            self::assertTrue($takeover->claimRun($uid));
            $stale->insertArtifact($uid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Done);
        });

        $this->orchestrator($stale, [$generator])->process($uid);

        self::assertInstanceOf(RunOwnershipLostException::class, $generator->thrown);
        self::assertSame(0, $this->artifactCount($uid));
        $row = $this->row($uid);
        self::assertSame('generating', $row['status']);
        self::assertSame(30, (int) $row['progress']);
        self::assertSame('', (string) $row['error_message']);
        self::assertNotSame('', $row['run_token'], 'The takeover keeps its claim');
    }

    /** The file a lost run stored for its artifact row is removed; the row keeps no file. */
    public function testALostRunsArtifactUpdateLeavesTheRowAndRemovesItsFile(): void
    {
        $uid   = $this->seedJob();
        $stale = $this->repository();
        self::assertTrue($stale->claimRun($uid));
        $artifact = $stale->insertArtifact($uid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Pending);
        $file     = $this->createFile('lost.pdf', '%PDF-1.4 lost');
        $this->backdateClaim($uid);
        self::assertTrue($this->repository()->claimRun($uid));

        try {
            $stale->updateArtifact($artifact, ['file_uid' => $file, 'status' => ArtifactStatus::Done->value]);
            self::fail('A lost run must not update its artifact');
        } catch (RunOwnershipLostException) {
            // Expected.
        }

        $row = $this->get(ConnectionPool::class)->getConnectionForTable(self::ARTIFACT_TABLE)
            ->select(['file_uid', 'status'], self::ARTIFACT_TABLE, ['uid' => $artifact])->fetchAssociative();
        self::assertSame(['file_uid' => 0, 'status' => 'pending'], ['file_uid' => (int) ($row['file_uid'] ?? -1), 'status' => $row['status'] ?? null]);
        self::assertSame(0, $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')->count('uid', 'sys_file', ['uid' => $file]));
    }

    /**
     * The usual order: the run that took over has already cleared the set, so the row the lost
     * run fills is gone. Its file is removed and the run stops.
     */
    public function testALostRunsUpdateOfAClearedRowRemovesItsFileAndStopsTheRun(): void
    {
        $uid   = $this->seedJob();
        $stale = $this->repository();
        self::assertTrue($stale->claimRun($uid));
        $artifact = $stale->insertArtifact($uid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Pending);
        $file     = $this->createFile('cleared.pdf', '%PDF-1.4 cleared');
        $this->backdateClaim($uid);
        $takeover = $this->repository();
        self::assertTrue($takeover->claimRun($uid));
        $takeover->deleteArtifactsForJob($uid);

        try {
            $stale->updateArtifact($artifact, ['file_uid' => $file]);
            self::fail('A lost run must stop when the row it fills is gone');
        } catch (RunOwnershipLostException) {
            // Expected.
        }

        self::assertSame(0, $this->artifactCount($uid));
        self::assertSame(0, $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')->count('uid', 'sys_file', ['uid' => $file]));
    }

    /** A lost run cannot clear the artifacts of the run that took the job over. */
    public function testALostRunCannotClearTheArtifactsOfTheRunThatTookOver(): void
    {
        $uid   = $this->seedJob();
        $stale = $this->repository();
        self::assertTrue($stale->claimRun($uid));
        $this->backdateClaim($uid);
        $takeover = $this->repository();
        self::assertTrue($takeover->claimRun($uid));
        $takeover->insertArtifact($uid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Done);

        try {
            $stale->deleteArtifactsForJob($uid);
            self::fail('A lost run must not clear the set');
        } catch (RunOwnershipLostException) {
            // Expected.
        }

        self::assertSame(1, $this->artifactCount($uid));
    }

    /** A lost run whose generator then crashes does not mark the job failed. */
    public function testALostRunThatCrashesDoesNotFailTheJob(): void
    {
        $uid       = $this->seedJob();
        $stale     = $this->repository();
        $generator = new ClaimTestGenerator($stale, function () use ($uid): never {
            $this->backdateClaim($uid);
            self::assertTrue($this->repository()->claimRun($uid));

            throw new SimulatedFailure('provider answered garbage');
        });

        $this->orchestrator($stale, [$generator])->process($uid);

        $row = $this->row($uid);
        self::assertSame('generating', $row['status']);
        self::assertSame('', (string) $row['error_message']);
    }

    /** The brief's review is written by the run that holds the job, not by a lost one. */
    public function testALostRunCannotStoreTheBriefReview(): void
    {
        $uid   = $this->seedJob();
        $stale = $this->repository();
        self::assertTrue($stale->claimRun($uid));
        $this->backdateClaim($uid);
        self::assertTrue($this->repository()->claimRun($uid));

        try {
            $stale->compareAndSetSourceReview($uid, ['mode' => 'off'], null);
            self::fail('A lost run must not store a review');
        } catch (RunOwnershipLostException) {
            // Expected.
        }

        self::assertNull($this->row($uid)['source_review']);
    }

    /** A claim older than the lease is a dead worker's: the next run takes the job over. */
    public function testAnExpiredClaimIsTakenOver(): void
    {
        $uid = $this->seedJob();
        self::assertTrue($this->repository()->claimRun($uid));
        $this->backdateClaim($uid);

        $jobs      = $this->repository();
        $generator = new ClaimTestGenerator($jobs);
        $this->orchestrator($jobs, [$generator])->process($uid);

        self::assertSame(1, $generator->runs);
        self::assertSame(1, $this->artifactCount($uid));
        $row = $this->row($uid);
        self::assertSame('done', $row['status']);
        self::assertSame('', $row['run_token'], 'A finished job holds no claim');
    }

    /** The blocked delivery comes back once the live claim would expire and leaves the job untouched. */
    public function testTheHandlerDeliversABlockedRunAgainAfterTheLease(): void
    {
        $uid = $this->seedJob();
        self::assertTrue($this->repository()->claimRun($uid));
        $before = $this->row($uid);
        $bus    = new RecordingMessageBus();

        $this->handler($bus, $this->repository())(new GenerateArtifactsMessage($uid));

        self::assertSame($before, $this->row($uid));
        self::assertCount(1, $bus->dispatched);
        $message = $bus->dispatched[0]['message'];
        self::assertInstanceOf(GenerateArtifactsMessage::class, $message);
        self::assertSame($uid, $message->jobUid);
        $stamps = $bus->dispatched[0]['stamps'];
        $delay  = array_values(array_filter($stamps, static fn (object $s): bool => $s instanceof DelayStamp));
        self::assertCount(1, $delay);
        self::assertInstanceOf(DelayStamp::class, $delay[0]);
        // A claim taken just now: one lease and the margin, less the second the test may take.
        $expected = Job::RUN_LEASE_SECONDS + GenerateArtifactsHandler::REDELIVERY_MARGIN_SECONDS;
        self::assertGreaterThanOrEqual(($expected - 1) * 1000, $delay[0]->getDelay());
        self::assertLessThanOrEqual($expected * 1000, $delay[0]->getDelay());
        $transport = array_values(array_filter($stamps, static fn (object $s): bool => $s instanceof TransportNamesStamp));
        self::assertCount(1, $transport);
        self::assertInstanceOf(TransportNamesStamp::class, $transport[0]);
        self::assertSame(['doctrine'], $transport[0]->getTransportNames());
    }

    /** Once the other run has finished, the message that came back does nothing: no loop. */
    public function testADeliveryAfterAFinishedRunDoesNothingAndIsNotSentAgain(): void
    {
        $uid   = $this->seedJob();
        $first = $this->repository();
        self::assertTrue($first->claimRun($uid));
        $first->finishJob($uid, JobStatus::Done, '');
        $before = $this->row($uid);
        $bus    = new RecordingMessageBus();

        $this->handler($bus, $this->repository())(new GenerateArtifactsMessage($uid));

        self::assertSame([], $bus->dispatched);
        self::assertSame($before, $this->row($uid));
    }

    /**
     * The other run finishes between this delivery's read of the job and its claim: the claim
     * refuses the finished job, and the delivery ends without being sent again.
     */
    public function testAJobThatFinishesBeforeTheClaimIsNotSentAgain(): void
    {
        $uid   = $this->seedJob();
        $first = $this->repository();
        self::assertTrue($first->claimRun($uid));
        $first->insertArtifact($uid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Done);
        $jobs = new class ($this->get(ConnectionPool::class), $this->get(ResourceFactory::class), $first) extends JobProcessingRepository {
            public function __construct(ConnectionPool $pool, ResourceFactory $files, private readonly JobProcessingRepository $first)
            {
                parent::__construct($pool, $files);
            }

            public function claimRun(int $jobUid): bool
            {
                $this->first->finishJob($jobUid, JobStatus::Done, '');

                return parent::claimRun($jobUid);
            }
        };
        $bus       = new RecordingMessageBus();
        $generator = new ClaimTestGenerator($jobs);

        $this->handler($bus, $jobs, [$generator])(new GenerateArtifactsMessage($uid));

        self::assertSame([], $bus->dispatched);
        self::assertSame(0, $generator->runs, 'A finished job is not run again');
        self::assertSame(1, $this->artifactCount($uid), 'The finished run keeps its artifacts');
        self::assertSame('done', $this->row($uid)['status']);
    }

    /**
     * The delay is what is left of the claim the delivery found, plus a margin, with a floor: a
     * delivery that finds a dead run's claim about to expire comes back soon, not a lease later.
     *
     * @return iterable<string, array{int, int}> seconds since the claim was taken, expected delay
     */
    public static function redeliveryDelays(): iterable
    {
        yield 'claim taken just now' => [0, Job::RUN_LEASE_SECONDS + GenerateArtifactsHandler::REDELIVERY_MARGIN_SECONDS];
        yield 'claim expires in 100 s' => [Job::RUN_LEASE_SECONDS - 100, 100 + GenerateArtifactsHandler::REDELIVERY_MARGIN_SECONDS];
        yield 'claim expires in 5 s' => [Job::RUN_LEASE_SECONDS - 5, GenerateArtifactsHandler::REDELIVERY_FLOOR_SECONDS];
    }

    #[DataProvider('redeliveryDelays')]
    public function testABlockedDeliveryWaitsForWhatIsLeftOfTheClaim(int $claimedAgo, int $expected): void
    {
        $uid = $this->seedJob();
        self::assertTrue($this->repository()->claimRun($uid));
        $this->get(ConnectionPool::class)->getConnectionForTable(self::JOB_TABLE)
            ->update(self::JOB_TABLE, ['run_claimed_at' => time() - $claimedAgo], ['uid' => $uid]);
        $bus = new RecordingMessageBus();

        $this->handler($bus, $this->repository())(new GenerateArtifactsMessage($uid));

        self::assertCount(1, $bus->dispatched);
        $delay = array_values(array_filter($bus->dispatched[0]['stamps'], static fn (object $s): bool => $s instanceof DelayStamp));
        self::assertCount(1, $delay);
        self::assertInstanceOf(DelayStamp::class, $delay[0]);
        // The test may take a second between the claim and the handler; the floor does not move.
        self::assertGreaterThanOrEqual(($expected - 1) * 1000, $delay[0]->getDelay());
        self::assertLessThanOrEqual($expected * 1000, $delay[0]->getDelay());
    }

    /**
     * A claim that cannot be read is no reason to lose the message: the handler sends it again
     * after one lease and the margin, and logs why (#238).
     */
    public function testABlockedDeliveryWhoseClaimCannotBeReadComesBackAfterOneLease(): void
    {
        $jobs = new class ($this->get(ConnectionPool::class), $this->get(ResourceFactory::class)) extends JobProcessingRepository {
            public function findRow(int $jobUid): ?array
            {
                throw new SimulatedFailure('database gone while reading the claim');
            }
        };
        $orchestrator = new readonly class implements GenerationOrchestratorInterface {
            public function process(int $jobUid): RunOutcome
            {
                throw new RunClaimHeldException('Another run holds job ' . $jobUid, 1795000002);
            }
        };
        $bus    = new RecordingMessageBus();
        $logger = new RecordingLogger();

        (new GenerateArtifactsHandler($orchestrator, $jobs, $logger, $bus))(new GenerateArtifactsMessage(7));

        self::assertCount(1, $bus->dispatched);
        $delay = array_values(array_filter($bus->dispatched[0]['stamps'], static fn (object $s): bool => $s instanceof DelayStamp));
        self::assertCount(1, $delay);
        self::assertInstanceOf(DelayStamp::class, $delay[0]);
        self::assertSame((Job::RUN_LEASE_SECONDS + GenerateArtifactsHandler::REDELIVERY_MARGIN_SECONDS) * 1000, $delay[0]->getDelay());
        self::assertSame(['The claim of a held job could not be read; it is sent again after one lease'], array_column($logger->records, 'message'));
    }

    /** Through the real bus the message lands in the doctrine queue, due once the claim would expire. */
    public function testTheMessageSentAgainIsQueuedOneLeaseAhead(): void
    {
        $uid = $this->seedJob();
        self::assertTrue($this->repository()->claimRun($uid));
        $jobs = $this->repository();

        (new GenerateArtifactsHandler($this->orchestrator($jobs, []), $jobs, new NullLogger(), $this->get(MessageBusInterface::class)))(new GenerateArtifactsMessage($uid));

        $rows = $this->get(ConnectionPool::class)->getConnectionForTable('sys_messenger_messages')
            ->select(['available_at', 'created_at', 'queue_name'], 'sys_messenger_messages')->fetchAllAssociative();
        self::assertCount(1, $rows);
        $delay    = strtotime((string) $rows[0]['available_at']) - strtotime((string) $rows[0]['created_at']);
        $expected = Job::RUN_LEASE_SECONDS + GenerateArtifactsHandler::REDELIVERY_MARGIN_SECONDS;
        self::assertGreaterThanOrEqual($expected - 2, $delay);
        self::assertLessThanOrEqual($expected + 1, $delay);
    }

    /** The message that came back after a dead worker's lease runs the job to its end. */
    public function testADeliveryAfterAnExpiredClaimTakesTheJobOver(): void
    {
        $uid = $this->seedJob();
        self::assertTrue($this->repository()->claimRun($uid));
        $this->backdateClaim($uid);
        $bus       = new RecordingMessageBus();
        $jobs      = $this->repository();
        $generator = new ClaimTestGenerator($jobs);

        $this->handler($bus, $jobs, [$generator])(new GenerateArtifactsMessage($uid));

        self::assertSame([], $bus->dispatched);
        self::assertSame(1, $generator->runs);
        self::assertSame('done', $this->row($uid)['status']);
    }

    /**
     * The source review fingerprints the job's PDF before ingestion. An edit of the reference
     * in between must not let ingestion read another file than the fingerprint names.
     */
    public function testAReferenceEditedBetweenCaptureAndIngestionDoesNotChangeTheFile(): void
    {
        $captured = $this->createFile('captured.pdf', '%PDF-1.4 captured');
        $swapped  = $this->createFile('swapped.pdf', '%PDF-1.4 swapped');
        $pool     = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable(self::JOB_TABLE)->insert(self::JOB_TABLE, [
            'pid' => 0, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'status' => 'queued',
        ]);
        $uid = (int) $pool->getConnectionForTable(self::JOB_TABLE)->lastInsertId();
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid'        => 0, 'uid_local' => $captured, 'uid_foreign' => $uid,
            'tablenames' => self::JOB_TABLE, 'fieldname' => 'source_pdf',
        ]);

        $resolver  = $this->get(PdfFileResolver::class);
        $ingestion = new readonly class ($resolver, $pool, $uid, $swapped) implements SourceIngestionServiceInterface {
            public function __construct(
                private PdfFileResolver $resolver,
                private ConnectionPool $pool,
                private int $uid,
                private int $swapped,
            ) {}

            public function ingest(JobSnapshot $job): SourceDocument
            {
                // The editor swaps the file after the capture, before the file is read.
                $this->pool->getConnectionForTable('sys_file_reference')
                    ->update('sys_file_reference', ['uid_local' => $this->swapped], ['uid_foreign' => $this->uid]);
                $text = (string) file_get_contents($this->resolver->resolve($job));

                return new SourceDocument('Doc', $text, 'pdf', 1, 'en');
            }
        };

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_repurpose']['sourceReviewMode'] = 'off';

        $config    = $this->get(ExtensionConfiguration::class);
        $policy    = new SourceReviewPolicy($config);
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions->method('evaluate')->willReturn(SourceReviewFixture::result());
        $jobs = $this->repository();
        $gate = new SourceReviewGate(
            new SourceReviewService($decisions, $policy),
            $policy,
            $jobs,
            new SourceReviewPointer($pool),
        );
        $generator = new ClaimTestGenerator($jobs);

        $this->orchestrator($jobs, [$generator], $ingestion, $gate)->process($uid);

        self::assertNotNull($generator->document);
        self::assertSame('%PDF-1.4 captured', $generator->document->text);
    }

    /** @return iterable<string, array{RunOutcome|null, int}> null: the run finds the job held */
    public static function commandOutcomes(): iterable
    {
        yield 'done' => [RunOutcome::Done, Command::SUCCESS];
        yield 'partially done' => [RunOutcome::PartiallyDone, Command::SUCCESS];
        yield 'finished before' => [RunOutcome::AlreadyFinished, Command::SUCCESS];
        yield 'failed before' => [RunOutcome::AlreadyFailed, Command::FAILURE];
        yield 'failed' => [RunOutcome::Failed, Command::FAILURE];
        yield 'lost to another run' => [RunOutcome::Lost, Command::FAILURE];
        yield 'not found' => [RunOutcome::NotFound, Command::FAILURE];
        yield 'held by another run' => [null, Command::FAILURE];
    }

    /** The CLI's exit code follows what this run did, not the absence of an exception. */
    #[DataProvider('commandOutcomes')]
    public function testTheGenerateCommandExitsWithTheRunsOutcome(?RunOutcome $outcome, int $exitCode): void
    {
        $orchestrator = new readonly class ($outcome) implements GenerationOrchestratorInterface {
            public function __construct(private ?RunOutcome $outcome) {}

            public function process(int $jobUid): RunOutcome
            {
                return $this->outcome ?? throw new RunClaimHeldException('held', 1);
            }
        };

        self::assertSame($exitCode, (new CommandTester(new GenerateCommand($orchestrator, $this->repository())))->execute(['jobUid' => '1']));
    }

    /** A job that had failed before this run stays a failure on the command line (#238). */
    public function testTheGenerateCommandFailsForAJobThatFailedBefore(): void
    {
        $uid = $this->seedJob();
        $this->get(ConnectionPool::class)->getConnectionForTable(self::JOB_TABLE)
            ->update(self::JOB_TABLE, ['status' => 'failed', 'error_message' => 'Source fidelity check failed', 'failure_reason' => 'source_review'], ['uid' => $uid]);

        $tester   = new CommandTester(new GenerateCommand($this->orchestrator($this->repository(), []), $this->repository()));
        $exitCode = $tester->execute(['jobUid' => (string) $uid]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Error: Source fidelity check failed (reason: source_review)', $tester->getDisplay());
    }

    /** A run that fails the job names the error and the reason on the command line (#238). */
    public function testTheGenerateCommandNamesTheFailure(): void
    {
        $uid = $this->seedJob();
        $this->get(ConnectionPool::class)->getConnectionForTable(self::JOB_TABLE)
            ->update(self::JOB_TABLE, ['status' => 'failed', 'error_message' => 'No text extracted', 'failure_reason' => 'source_empty'], ['uid' => $uid]);
        $orchestrator = new readonly class implements GenerationOrchestratorInterface {
            public function process(int $jobUid): RunOutcome
            {
                return RunOutcome::Failed;
            }
        };

        $tester = new CommandTester(new GenerateCommand($orchestrator, $this->repository()));
        self::assertSame(Command::FAILURE, $tester->execute(['jobUid' => (string) $uid]));
        self::assertStringContainsString('Error: No text extracted (reason: source_empty)', $tester->getDisplay());
    }

    /**
     * A takeover while a generator works is no failure of this run: the generator's catch passes
     * it on, and neither the generator nor the orchestrator logs an error (#238).
     */
    public function testATakeoverDuringGenerationLogsNoError(): void
    {
        $uid       = $this->seedJob();
        $stale     = $this->repository();
        $logger    = new RecordingLogger();
        $generator = new TakeoverDuringGenerationGenerator($stale, $logger, function () use ($uid): void {
            $this->backdateClaim($uid);
            self::assertTrue($this->repository()->claimRun($uid));
        });

        self::assertSame(RunOutcome::Lost, $this->orchestrator($stale, [$generator], null, null, $logger)->process($uid));

        self::assertSame([], array_values(array_filter($logger->records, static fn (array $r): bool => in_array($r['level'], ['error', 'critical', 'alert', 'emergency'], true))));
        self::assertSame('generating', $this->row($uid)['status']);
    }

    /**
     * A run that loses the job fails on the command line even when the run that took it over
     * finished it: the command reports this run, not the job's status (#238).
     */
    public function testARunThatLostTheJobFailsEvenWhenTheWinnerFinished(): void
    {
        $uid       = $this->seedJob();
        $stale     = $this->repository();
        $generator = new ClaimTestGenerator($stale, function () use ($uid): void {
            $this->backdateClaim($uid);
            $winner = $this->repository();
            self::assertTrue($winner->claimRun($uid));
            $winner->finishJob($uid, JobStatus::Done, '');
        });

        $exitCode = (new CommandTester(new GenerateCommand($this->orchestrator($stale, [$generator]), $stale)))->execute(['jobUid' => (string) $uid]);

        self::assertSame('done', $this->row($uid)['status']);
        self::assertSame(Command::FAILURE, $exitCode);
    }

    /**
     * A failure before the claim reaches the handler's catch. While another run holds a live
     * claim, that run owns the outcome: the job and its claim stay as they are.
     */
    public function testAFailureBeforeTheClaimLeavesAJobThatALiveRunHolds(): void
    {
        $uid  = $this->seedJob();
        $live = $this->repository();
        self::assertTrue($live->claimRun($uid));
        $live->markStatus($uid, JobStatus::Generating, 'generating', 50);
        $before = $this->row($uid);

        $this->crashingHandler()(new GenerateArtifactsMessage($uid));

        self::assertSame($before, $this->row($uid));
    }

    /** With the claim expired the failure is recorded and the dead run's claim cleared. */
    public function testAFailureBeforeTheClaimFailsAJobWhoseClaimExpired(): void
    {
        $uid = $this->seedJob();
        self::assertTrue($this->repository()->claimRun($uid));
        $this->backdateClaim($uid);

        $this->crashingHandler()(new GenerateArtifactsMessage($uid));

        $row = $this->row($uid);
        self::assertSame('failed', $row['status']);
        self::assertSame('', $row['run_token']);
        self::assertSame(0, (int) $row['run_claimed_at']);
    }

    /** A job queued again from the backend starts without the claim of an earlier run. */
    public function testRequeueingAFailedJobClearsItsClaim(): void
    {
        $uid = $this->seedJob();
        $this->get(ConnectionPool::class)->getConnectionForTable(self::JOB_TABLE)->update(
            self::JOB_TABLE,
            ['status' => 'failed', 'run_token' => 'left-behind', 'run_claimed_at' => time()],
            ['uid' => $uid],
        );

        self::assertTrue($this->repository()->requeueFailed($uid));

        $row = $this->row($uid);
        self::assertSame('queued', $row['status']);
        self::assertSame('', $row['run_token']);
        self::assertSame(0, (int) $row['run_claimed_at']);
    }

    /** The backend's retry clears artifacts without a claim; a live run's set stays. */
    public function testAnUnclaimedClearLeavesTheArtifactsOfALiveRun(): void
    {
        $uid  = $this->seedJob();
        $live = $this->repository();
        self::assertTrue($live->claimRun($uid));
        $live->insertArtifact($uid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Done);

        $this->repository()->deleteArtifactsForJob($uid);

        self::assertSame(1, $this->artifactCount($uid));
    }

    /** A run that still holds the job but finds its artifact row deleted records a failure. */
    public function testAnArtifactRowThatIsGoneUnderALiveClaimReportsNothingStored(): void
    {
        $uid  = $this->seedJob();
        $jobs = $this->repository();
        self::assertTrue($jobs->claimRun($uid));
        $artifact = $jobs->insertArtifact($uid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Pending);
        $file     = $this->createFile('gone.pdf', '%PDF-1.4 gone');
        $this->get(ConnectionPool::class)->getConnectionForTable(self::ARTIFACT_TABLE)
            ->delete(self::ARTIFACT_TABLE, ['uid' => $artifact]);

        self::assertFalse($jobs->updateArtifact($artifact, ['file_uid' => $file]), 'A missing row is not a stored artifact');
        self::assertSame(0, $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')->count('uid', 'sys_file', ['uid' => $file]));
    }

    /**
     * The stored brief review changes outside any run while this run still holds the job: the
     * run cannot trust its baseline and fails the job through its claim, which it clears.
     */
    public function testASupersededReviewUnderALiveClaimFailsTheJob(): void
    {
        $uid                                                                          = $this->seedJob();
        $pool                                                                         = $this->get(ConnectionPool::class);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_repurpose']['sourceReviewMode'] = 'observe';

        $config    = $this->get(ExtensionConfiguration::class);
        $policy    = new SourceReviewPolicy($config);
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions->method('evaluate')->willReturnCallback(static function () use ($pool, $uid): DecisionResult {
            $pool->getConnectionForTable(self::JOB_TABLE)
                ->update(self::JOB_TABLE, ['source_review' => '{"mode":"observe","status":"edited"}'], ['uid' => $uid]);

            return SourceReviewFixture::result();
        });
        $jobs      = $this->repository();
        $generator = new ClaimTestGenerator($jobs);

        $this->orchestrator($jobs, [$generator], null, new SourceReviewGate(new SourceReviewService($decisions, $policy), $policy, $jobs))->process($uid);

        $row = $this->row($uid);
        self::assertSame(0, $generator->runs);
        self::assertSame('failed', $row['status']);
        self::assertSame('Source fidelity check failed', $row['error_message']);
        self::assertSame('', $row['run_token']);
    }

    /**
     * A lost claim below a wrapper (a RenderingException around a provider call) is no failure
     * of this run: not in a step, where failJob() passes it on, and not in a generator, where
     * the orchestrator's catch finds it (#238).
     */
    public function testALostClaimBelowAWrapperEndsTheRunAsLost(): void
    {
        $wrapped = static fn (): RenderingException => RenderingException::because(
            'Image generation failed',
            1749400520,
            new RunOwnershipLostException('Another run holds the job now', 1795000001),
        );

        $uid       = $this->seedJob();
        $logger    = new RecordingLogger();
        $ingestion = new readonly class ($wrapped) implements SourceIngestionServiceInterface {
            public function __construct(private Closure $failure) {}

            public function ingest(JobSnapshot $job): SourceDocument
            {
                throw ($this->failure)();
            }
        };
        self::assertSame(RunOutcome::Lost, $this->orchestrator($this->repository(), [], $ingestion, null, $logger)->process($uid), 'in a step');
        self::assertSame('ingesting', $this->row($uid)['status']);
        self::assertSame([], $this->errorRecords($logger));

        $uid       = $this->seedJob();
        $logger    = new RecordingLogger();
        $generator = new ClaimTestGenerator($this->repository(), static function () use ($wrapped): never {
            throw $wrapped();
        });
        self::assertSame(RunOutcome::Lost, $this->orchestrator($this->repository(), [$generator], null, null, $logger)->process($uid), 'in a generator');
        self::assertSame('generating', $this->row($uid)['status']);
        self::assertSame([], $this->errorRecords($logger));
    }

    /** The container hands the analyzer and the ingestion the worker's own repository. */
    public function testTheLongStepsRenewThroughTheWorkersRepository(): void
    {
        $jobs      = $this->get(JobProcessingRepository::class);
        $ingestion = $this->get(SourceIngestionService::class);
        self::assertSame($jobs, $this->property($ingestion, 'heartbeat'));

        // Every step that bypasses nr-llm's pipeline renews first: each page fetch (url and page
        // sources), the PDF download, each poppler process, each Chromium and ffmpeg process.
        $fetcher = $this->property($ingestion, 'webPageFetcher');
        self::assertIsObject($fetcher);
        self::assertSame($jobs, $this->property($fetcher, 'heartbeat'));
        $pageFetcher = $this->property($this->property($ingestion, 'pageSourceReader'), 'fetcher');
        self::assertSame($fetcher, $pageFetcher);
        self::assertSame($jobs, $this->property($this->get(PdfFileResolver::class), 'heartbeat'));
        self::assertSame($jobs, $this->property($this->property($this->property($ingestion, 'layoutExtractor'), 'poppler'), 'heartbeat'));
        $renderer = $this->property($this->get(StoryGenerator::class), 'renderer');
        self::assertSame($jobs, $this->property($this->property($renderer, 'processRunner'), 'heartbeat'));

        // Every provider call goes through nr-llm's pipeline; the renewal is its innermost
        // layer, so it runs directly before each dispatch, fallback attempts included.
        $middleware = (new ReflectionProperty(MiddlewarePipeline::class, 'middleware'))->getValue($this->get(MiddlewarePipeline::class));
        self::assertIsArray($middleware);
        $innermost = end($middleware);
        self::assertInstanceOf(RunClaimRenewalMiddleware::class, $innermost);
        self::assertSame($jobs, (new ReflectionProperty(RunClaimRenewalMiddleware::class, 'heartbeat'))->getValue($innermost));
    }

    /** A provider call through nr-llm's pipeline renews the claim this process holds. */
    public function testAProviderCallRenewsTheRunsClaim(): void
    {
        $uid  = $this->seedJob();
        $jobs = $this->get(JobProcessingRepository::class);
        self::assertTrue($jobs->claimRun($uid));
        $this->get(ConnectionPool::class)->getConnectionForTable(self::JOB_TABLE)
            ->update(self::JOB_TABLE, ['run_claimed_at' => time() - 600], ['uid' => $uid]);
        $claimedAtTheCall = null;

        try {
            $answer = $this->get(MiddlewarePipeline::class)->run(
                ProviderCallContext::for(ProviderOperation::Chat, []),
                function () use ($uid, &$claimedAtTheCall): string {
                    $claimedAtTheCall = (int) $this->row($uid)['run_claimed_at'];

                    return 'answer';
                },
            );
        } finally {
            $jobs->forgetRun($uid);
        }

        self::assertSame('answer', $answer);
        self::assertGreaterThanOrEqual(time() - 5, $claimedAtTheCall, 'renewed before the provider call');
    }

    /** A provider call of a run that lost its job does not happen: the renewal stops the run. */
    public function testAProviderCallOfALostRunIsNotMade(): void
    {
        $uid  = $this->seedJob();
        $jobs = $this->get(JobProcessingRepository::class);
        self::assertTrue($jobs->claimRun($uid));
        $this->backdateClaim($uid);
        self::assertTrue($this->repository()->claimRun($uid));
        $called = false;

        try {
            $this->get(MiddlewarePipeline::class)->run(
                ProviderCallContext::for(ProviderOperation::Chat, []),
                static function () use (&$called): string {
                    $called = true;

                    return 'answer';
                },
            );
            self::fail('A lost run must not make a provider call');
        } catch (RunOwnershipLostException) {
            // Expected, unwrapped by nr-llm's middlewares.
        } finally {
            $jobs->forgetRun($uid);
        }

        self::assertFalse($called);
    }

    /** A send that fails once is tried again: the held job keeps its message. */
    public function testASendThatFailsOnceIsTriedAgain(): void
    {
        $uid = $this->seedJob();
        self::assertTrue($this->repository()->claimRun($uid));
        $bus = new FailingMessageBus(1);

        $this->handler($bus, $this->repository())(new GenerateArtifactsMessage($uid));

        self::assertSame(2, $bus->attempts);
        self::assertCount(1, $bus->dispatched);
    }

    /**
     * A send that keeps failing is not swallowed: the delivery fails, so the worker logs it, and
     * the job, which a live run holds, is left as it is.
     */
    public function testASendThatKeepsFailingFailsTheDelivery(): void
    {
        $uid = $this->seedJob();
        self::assertTrue($this->repository()->claimRun($uid));
        $before = $this->row($uid);
        $bus    = new FailingMessageBus(PHP_INT_MAX);

        try {
            $this->handler($bus, $this->repository())(new GenerateArtifactsMessage($uid));
            self::fail('A send that keeps failing must fail the delivery');
        } catch (TransportException) {
            // Expected.
        }

        self::assertSame(3, $bus->attempts);
        self::assertSame($before, $this->row($uid));
    }

    /**
     * A crash whose own failure write fails too (the database dropped the connection): the
     * handler's fallback write accepts the claim this process just held, so the job still ends
     * failed and not stuck under a live-looking claim.
     */
    public function testACrashWhoseFailureWriteFailsStillEndsFailed(): void
    {
        $uid  = $this->seedJob();
        $jobs = new class ($this->get(ConnectionPool::class), $this->get(ResourceFactory::class)) extends JobProcessingRepository {
            private int $failingWrites = 1;

            public function markFailed(int $jobUid, string $error, FailureReason $reason): void
            {
                if ($this->failingWrites-- > 0) {
                    throw new SimulatedFailure('Lost connection to the database server during query');
                }

                parent::markFailed($jobUid, $error, $reason);
            }
        };
        $generator = new ClaimTestGenerator($jobs, static function (): never {
            throw new SimulatedFailure('provider answered garbage');
        });

        (new GenerateArtifactsHandler($this->orchestrator($jobs, [$generator]), $jobs, new NullLogger(), new RecordingMessageBus()))(new GenerateArtifactsMessage($uid));

        $row = $this->row($uid);
        self::assertSame('failed', $row['status']);
        self::assertSame('Generation failed', $row['error_message']);
        self::assertSame('', $row['run_token']);
    }

    /** A failure before the claim never turns a finished job into a failed one. */
    public function testAFailureBeforeTheClaimLeavesAFinishedJob(): void
    {
        $uid  = $this->seedJob();
        $jobs = $this->repository();
        self::assertTrue($jobs->claimRun($uid));
        $jobs->finishJob($uid, JobStatus::Done, '');
        $before = $this->row($uid);

        $this->crashingHandler()(new GenerateArtifactsMessage($uid));

        self::assertSame($before, $this->row($uid));
    }

    /** The captured file is deleted before ingestion reads it: the job fails as one without a PDF. */
    public function testACapturedFileDeletedBeforeIngestionFailsTheJobAsMissing(): void
    {
        $captured = $this->createFile('deleted.pdf', '%PDF-1.4 deleted');
        $pool     = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable(self::JOB_TABLE)->insert(self::JOB_TABLE, [
            'pid' => 0, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'status' => 'queued',
        ]);
        $uid = (int) $pool->getConnectionForTable(self::JOB_TABLE)->lastInsertId();
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid'        => 0, 'uid_local' => $captured, 'uid_foreign' => $uid,
            'tablenames' => self::JOB_TABLE, 'fieldname' => 'source_pdf',
        ]);
        $ingestion = new readonly class ($this->get(PdfFileResolver::class), $pool, $captured) implements SourceIngestionServiceInterface {
            public function __construct(private PdfFileResolver $resolver, private ConnectionPool $pool, private int $file) {}

            public function ingest(JobSnapshot $job): SourceDocument
            {
                $this->pool->getConnectionForTable('sys_file')->delete('sys_file', ['uid' => $this->file]);
                $this->resolver->resolve($job);

                return new SourceDocument('Doc', 'never read', 'pdf', 1, 'en');
            }
        };
        $jobs      = $this->repository();
        $generator = new ClaimTestGenerator($jobs);

        $this->orchestrator($jobs, [$generator], $ingestion, $this->offGate($jobs))->process($uid);

        $row = $this->row($uid);
        self::assertSame(0, $generator->runs);
        self::assertSame('failed', $row['status']);
        self::assertSame('pdf_fal job has no attached PDF (source_pdf empty)', $row['error_message']);
    }

    /** @return iterable<string, array{0: int, 1: string, 2: bool, 3?: bool}> seconds since the last write, claim state, retry accepted, a done artifact */
    public static function stalledJobs(): iterable
    {
        yield 'stalled, claim expired' => [Job::STALLED_AFTER + 60, 'expired', true];
        yield 'stalled, no claim' => [Job::STALLED_AFTER + 60, 'none', true];
        yield 'stalled, live claim' => [Job::STALLED_AFTER + 60, 'live', false];
        yield 'written recently, no claim' => [60, 'none', false];
        yield 'stalled, no claim, a finished artifact' => [Job::STALLED_AFTER + 60, 'none', false, true];
        yield 'never written, no claim' => [self::NEVER_WRITTEN, 'none', false];
    }

    /**
     * A job left unfinished by a dead worker whose message is gone can be started again, as a
     * failed one can; a job a live run holds, or one written recently, cannot.
     */
    #[DataProvider('stalledJobs')]
    public function testAStalledJobWithoutALiveRunCanBeStartedAgain(int $idle, string $claim, bool $accepted, bool $doneArtifact = false): void
    {
        $uid = $this->seedJob();
        if ($doneArtifact) {
            // Finished results stay: a caller that skips Job::isRetryable() cannot delete them.
            $this->repository()->insertArtifact($uid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Done);
        }

        $this->get(ConnectionPool::class)->getConnectionForTable(self::JOB_TABLE)->update(self::JOB_TABLE, [
            'status'         => 'generating',
            'tstamp'         => $idle === self::NEVER_WRITTEN ? 0 : time() - $idle,
            'run_token'      => $claim === 'none' ? '' : 'dead-or-alive',
            'run_claimed_at' => match ($claim) {
                'none'    => 0,
                'expired' => time() - Job::RUN_LEASE_SECONDS - 1,
                default   => time(),
            },
        ], ['uid' => $uid]);

        $job = $this->get(JobRepository::class)->findByUid($uid);
        self::assertInstanceOf(Job::class, $job);
        self::assertSame($accepted, $job->isRetryable(), 'the result view offers the button exactly where the server accepts it');
        self::assertSame($accepted, $this->repository()->requeueFailed($uid));
        self::assertSame($accepted ? 'queued' : 'generating', $this->row($uid)['status']);
        self::assertSame($doneArtifact ? 1 : 0, $this->artifactCount($uid));
    }

    /** A run that only renews (a long OCR) is not stalled: the renewal writes tstamp too. */
    public function testARenewingRunIsNotStalled(): void
    {
        $uid  = $this->seedJob();
        $jobs = $this->repository();
        self::assertTrue($jobs->claimRun($uid));
        $this->get(ConnectionPool::class)->getConnectionForTable(self::JOB_TABLE)
            ->update(self::JOB_TABLE, ['status' => 'ingesting', 'tstamp' => time() - Job::STALLED_AFTER - 60], ['uid' => $uid]);

        $jobs->renew($uid);

        $job = $this->get(JobRepository::class)->findByUid($uid);
        self::assertInstanceOf(Job::class, $job);
        self::assertNull($job->getFailure());
        self::assertFalse($job->isRetryable());
    }

    /**
     * One format's row is deleted while the run holds the job: that format counts as failed,
     * the next format still runs, and the job ends by count (#238).
     */
    public function testADeletedArtifactRowFailsOnlyItsFormat(): void
    {
        $uid   = $this->seedJob();
        $jobs  = $this->repository();
        $first = new RowLosingGenerator($jobs, $this->get(ConnectionPool::class));
        $next  = new ClaimTestGenerator($jobs);

        $this->orchestrator($jobs, [$first, $next])->process($uid);

        self::assertSame(1, $next->runs, 'the next format still runs');
        $row = $this->row($uid);
        self::assertSame('partially_done', $row['status']);
        self::assertSame('1 of 2 formats failed; each failed artifact shows its error', $row['error_message']);
        self::assertSame(1, $this->artifactCount($uid));
    }

    private function offGate(JobProcessingRepository $jobs): SourceReviewGate
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_repurpose']['sourceReviewMode'] = 'off';

        $policy    = new SourceReviewPolicy($this->get(ExtensionConfiguration::class));
        $decisions = $this->createMock(DecisionServiceInterface::class);
        $decisions->method('evaluate')->willReturn(SourceReviewFixture::result());

        return new SourceReviewGate(new SourceReviewService($decisions, $policy), $policy, $jobs, new SourceReviewPointer($this->get(ConnectionPool::class)));
    }

    private function crashingHandler(): GenerateArtifactsHandler
    {
        $orchestrator = new readonly class implements GenerationOrchestratorInterface {
            public function process(int $jobUid): RunOutcome
            {
                throw new SimulatedFailure('database gone while reading the job');
            }
        };

        return new GenerateArtifactsHandler($orchestrator, $this->repository(), new NullLogger(), new RecordingMessageBus());
    }

    private function repository(): JobProcessingRepository
    {
        return new JobProcessingRepository($this->get(ConnectionPool::class), $this->get(ResourceFactory::class));
    }

    /** @param list<ArtifactGeneratorInterface> $generators */
    private function orchestrator(
        JobProcessingRepository $jobs,
        array $generators,
        ?SourceIngestionServiceInterface $ingestion = null,
        ?SourceReviewGate $gate = null,
        ?LoggerInterface $logger = null,
    ): GenerationOrchestrator {
        return new GenerationOrchestrator(
            $jobs,
            $logger ?? new NullLogger(),
            $ingestion ?? new readonly class implements SourceIngestionServiceInterface {
                public function ingest(JobSnapshot $job): SourceDocument
                {
                    return new SourceDocument('Doc', 'Body.', 'https://example.com/', 0, 'en');
                }
            },
            new readonly class implements DocumentAnalyzerInterface {
                public function analyze(SourceDocument $document, JobSnapshot $job): ContentBrief
                {
                    return new ContentBrief('Doc', 'Summary.', ['Point'], [], 'Analysts', 'en');
                }
            },
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->get(CapabilityGrantResolver::class),
            $this->get(AiLabelSettingsFactory::class),
            $generators,
            $gate,
        );
    }

    /** @param list<ArtifactGeneratorInterface> $generators */
    private function handler(MessageBusInterface $bus, JobProcessingRepository $jobs, array $generators = []): GenerateArtifactsHandler
    {
        return new GenerateArtifactsHandler($this->orchestrator($jobs, $generators), $jobs, new NullLogger(), $bus);
    }

    private function property(mixed $object, string $name): mixed
    {
        self::assertIsObject($object);

        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    /** @return list<array{level: mixed, message: string}> */
    private function errorRecords(RecordingLogger $logger): array
    {
        return array_values(array_filter($logger->records, static fn (array $r): bool => in_array($r['level'], ['error', 'critical', 'alert', 'emergency'], true)));
    }

    private function seedJob(): int
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(self::JOB_TABLE);
        $connection->insert(self::JOB_TABLE, [
            'pid' => 0, 'source_type' => 'url', 'source_value' => 'https://example.com/', 'status' => 'queued',
        ]);

        return (int) $connection->lastInsertId();
    }

    /** The claim's last renewal lies beyond the lease, as a dead worker leaves it. */
    private function backdateClaim(int $uid): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable(self::JOB_TABLE)->update(
            self::JOB_TABLE,
            ['run_claimed_at' => time() - Job::RUN_LEASE_SECONDS - 1],
            ['uid' => $uid],
        );
    }

    /** @return array<string, mixed> */
    private function row(int $uid): array
    {
        $row = $this->get(ConnectionPool::class)->getConnectionForTable(self::JOB_TABLE)
            ->select(['*'], self::JOB_TABLE, ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function artifactCount(int $uid): int
    {
        return (int) $this->get(ConnectionPool::class)->getConnectionForTable(self::ARTIFACT_TABLE)
            ->count('uid', self::ARTIFACT_TABLE, ['job' => $uid]);
    }

    private function createFile(string $name, string $contents): int
    {
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $folder = $storage->hasFolder('claim') ? $storage->getFolder('claim') : $storage->createFolder('claim');
        $file   = $storage->createFile($name, $folder);
        $file->setContents($contents);

        return $file->getUid();
    }
}
