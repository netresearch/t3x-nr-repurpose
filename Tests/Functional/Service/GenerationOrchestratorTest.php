<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Netresearch\NrLlm\Testing\FakeBudgetService;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\ValueObject\CapabilityGrants;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Generator\ExecutiveSummaryGenerator;
use Netresearch\NrRepurpose\Generator\FaqGenerator;
use Netresearch\NrRepurpose\Generator\NewsletterGenerator;
use Netresearch\NrRepurpose\Generator\SocialPostGenerator;
use Netresearch\NrRepurpose\Generator\Support\TextLimiter;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Pipeline\JobProgress;
use Netresearch\NrRepurpose\Pipeline\PromptSnippetResolver;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolver;
use Netresearch\NrRepurpose\Service\GenerationOrchestrator;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use Netresearch\NrVault\Security\TechnicalActor;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class GenerationOrchestratorTest extends AbstractFunctionalTestCase
{
    private const QUARTERLY_REPORT = 'Quarterly report';

    private const SOURCE_URL = 'https://example.com/';

    private function seedJob(int $beUser = 0): int
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid'    => 0, 'source_type' => 'url', 'source_value' => self::SOURCE_URL,
            'theme'  => 'nr', 'want_podcast' => 1, 'want_schaubild' => 1, 'want_story' => 1,
            'status' => 'queued', 'be_user' => $beUser,
        ]);

        return (int) $conn->lastInsertId();
    }

    private function stubDocument(string $title = 'Doc', string $text = 'Body.'): SourceDocument
    {
        return new SourceDocument(
            title: $title,
            text: $text,
            sourceLabel: self::SOURCE_URL,
            pageCount: 0,
            languageHint: 'en',
        );
    }

    private function stubBrief(string $title = 'Doc'): ContentBrief
    {
        return new ContentBrief($title, 'Summary.', ['Point'], [], 'Analysts', 'en');
    }

    private function stubIngestion(SourceDocument $document): SourceIngestionServiceInterface
    {
        return new class ($document) implements SourceIngestionServiceInterface {
            public function __construct(private readonly SourceDocument $document) {}

            public function ingest(array $jobRow): SourceDocument
            {
                return $this->document;
            }
        };
    }

    private function stubAnalyzer(ContentBrief $brief): DocumentAnalyzerInterface
    {
        return new class ($brief) implements DocumentAnalyzerInterface {
            public function __construct(private readonly ContentBrief $brief) {}

            public function analyze(SourceDocument $document, array $jobRow): ContentBrief
            {
                return $this->brief;
            }
        };
    }

    public function testProcessRunsIngestAnalyzeGenerateAndEndsDone(): void
    {
        $jobUid = $this->seedJob();

        $document = $this->stubDocument(self::QUARTERLY_REPORT, 'Revenue grew across all regions.');
        $brief    = $this->stubBrief(self::QUARTERLY_REPORT);

        $jobs      = $this->get(JobProcessingRepository::class);
        $generator = new RecordingArtifactGenerator($jobs);

        $orchestrator = new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $this->stubIngestion($document),
            $this->stubAnalyzer($brief),
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->get(CapabilityGrantResolver::class),
            [$generator],
        );
        $orchestrator->process($jobUid);

        self::assertInstanceOf(GenerationContext::class, $generator->seen);
        self::assertSame('nr', $generator->seen->theme);
        self::assertSame(self::QUARTERLY_REPORT, $generator->seen->brief->title);
        self::assertSame('Revenue grew across all regions.', $generator->seen->document->text);
        // A job without a prompt-snippet selection resolves to the empty default (real resolver).
        self::assertSame([], $generator->seen->snippets->personas);
        self::assertSame('', $generator->seen->snippets->schaubildSections);
        self::assertSame('', $generator->seen->snippets->storySections);

        // The orchestrator hands every generator a progress reporter scoped to its band
        // (one generator -> 30..100); the step is persisted verbatim with the mapped percent.
        self::assertInstanceOf(JobProgress::class, $generator->seen->progress);
        self::assertSame('generating', $generator->rowAfterStep['status']);
        self::assertSame('Stub: halfway there', $generator->rowAfterStep['current_step']);
        self::assertSame(65, (int) $generator->rowAfterStep['progress']);

        $row = $jobs->findRow($jobUid);
        self::assertSame('done', $row['status']);
        self::assertSame(100, (int) $row['progress']);
        self::assertSame('en', $row['language_detected']);

        $artifactCount = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->count('uid', 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid, 'status' => 'done']);
        self::assertSame(1, $artifactCount);
    }

    /**
     * The acceptance criterion of the text formats: a job that asks for an FAQ ends with a
     * stored FAQ artifact — real orchestrator, real generators, real database; only the
     * LLM is faked. The other text formats are wired in but not requested, so the run also
     * proves the want_* flags select generators.
     */
    public function testAJobRequestingAnFaqProducesAnFaqArtifact(): void
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid'               => 0, 'source_type' => 'url', 'source_value' => self::SOURCE_URL,
            'theme'             => 'nr', 'want_podcast' => 0, 'want_schaubild' => 0, 'want_story' => 0,
            'want_exec_summary' => 0, 'want_faq' => 1, 'want_social_post' => 0, 'want_newsletter' => 0,
            'status'            => 'queued',
        ]);
        $jobUid = (int) $conn->lastInsertId();

        $jobs                         = $this->get(JobProcessingRepository::class);
        $completion                   = new FakeCompletionService();
        $completion->structuredResult = ['faq' => [
            ['question' => 'How much did revenue grow?', 'answer' => 'By twelve percent.'],
            ['question' => 'Where did a branch open?', 'answer' => 'In Leipzig.'],
        ]];
        $budget = new FakeBudgetService();
        $logger = new NullLogger();

        $orchestrator = new GenerationOrchestrator(
            $jobs,
            $logger,
            $this->stubIngestion($this->stubDocument(self::QUARTERLY_REPORT, 'Revenue grew by twelve percent.')),
            $this->stubAnalyzer($this->stubBrief(self::QUARTERLY_REPORT)),
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->get(CapabilityGrantResolver::class),
            [
                new ExecutiveSummaryGenerator($jobs, $budget, $logger, $completion),
                new FaqGenerator($jobs, $budget, $logger, $completion),
                new SocialPostGenerator($jobs, $budget, $logger, $completion, new TextLimiter()),
                new NewsletterGenerator($jobs, $budget, $logger, $completion),
            ],
        );
        $orchestrator->process($jobUid);

        self::assertSame('done', $jobs->findRow($jobUid)['status'] ?? null);
        self::assertCount(1, $completion->completeStructuredCalls);

        $rows = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->select(['type', 'variant', 'status', 'script_text', 'metadata'], 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid])
            ->fetchAllAssociative();
        self::assertCount(1, $rows);
        self::assertSame('faq', $rows[0]['type']);
        self::assertSame('done', $rows[0]['status']);
        self::assertStringStartsWith("Q: How much did revenue grow?\nA: By twelve percent.", (string) $rows[0]['script_text']);
        $metadata = json_decode((string) $rows[0]['metadata'], true);
        self::assertIsArray($metadata);
        self::assertSame('Where did a branch open?', $metadata['content']['faq'][1]['question']);
        self::assertStringContainsString('"@type": "FAQPage"', (string) $metadata['content']['jsonLd']);
    }

    public function testIngestionFailureMarksJobFailedAndRunsNoGenerator(): void
    {
        $jobUid = $this->seedJob();
        $jobs   = $this->get(JobProcessingRepository::class);

        $ingestion = new class implements SourceIngestionServiceInterface {
            public function ingest(array $jobRow): SourceDocument
            {
                // The real contract: SourceIngestionServiceInterface::ingest() throws
                // IngestionException on an unreachable source.
                throw new IngestionException('source unreachable');
            }
        };
        $analyzer = new class implements DocumentAnalyzerInterface {
            public bool $called = false;

            public function analyze(SourceDocument $document, array $jobRow): ContentBrief
            {
                $this->called = true;

                return new ContentBrief('t', 's', [], [], 'a', 'en');
            }
        };
        $generator = new class implements ArtifactGeneratorInterface {
            public bool $called = false;

            public function supports(GenerationContext $ctx): bool
            {
                return true;
            }

            public function generate(GenerationContext $ctx): bool
            {
                $this->called = true;

                return true;
            }
        };

        $orchestrator = new GenerationOrchestrator($jobs, new NullLogger(), $ingestion, $analyzer, $this->get(PromptSnippetResolver::class), $this->get(TechnicalActorContextInterface::class), $this->get(ExtensionConfiguration::class), $this->get(CapabilityGrantResolver::class), [$generator]);
        $orchestrator->process($jobUid);

        $row = $jobs->findRow($jobUid);
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('source unreachable', (string) $row['error_message']);
        self::assertFalse($analyzer->called);
        self::assertFalse($generator->called);
    }

    public function testReprocessingClearsPriorArtifacts(): void
    {
        $jobUid = $this->seedJob();
        $jobs   = $this->get(JobProcessingRepository::class);

        // A prior (interrupted) run left a stale artifact row for this job.
        $jobs->insertArtifact($jobUid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Failed);

        $orchestrator = new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $this->stubIngestion($this->stubDocument()),
            $this->stubAnalyzer($this->stubBrief()),
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->get(CapabilityGrantResolver::class),
            [new RecordingArtifactGenerator($jobs)],
        );
        $orchestrator->process($jobUid);

        // The stale row is cleared before generation; only the fresh artifact remains (no duplicate).
        $total = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->count('uid', 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid]);
        self::assertSame(1, $total);
    }

    /**
     * The reason this class takes a TechnicalActorContextInterface at all: both callers run
     * without an authenticated backend user, and nr_vault then denies every secret read. The
     * generator asserting inside its own generate() is the point - it proves the scope is open
     * for the whole job, not merely entered and left around it.
     */
    public function testRunsTheWholeJobInsideTheTechnicalActorScopeWhenOneIsConfigured(): void
    {
        $jobUid    = $this->seedJob();
        $jobs      = $this->get(JobProcessingRepository::class);
        $actor     = new RecordingTechnicalActorContext();
        $generator = new ScopeAssertingArtifactGenerator($jobs, $actor);

        $orchestrator = new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $this->stubIngestion($this->stubDocument()),
            $this->stubAnalyzer($this->stubBrief()),
            $this->get(PromptSnippetResolver::class),
            $actor,
            $this->extensionConfigurationWithActorUid(4711),
            $this->get(CapabilityGrantResolver::class),
            [$generator],
        );
        $orchestrator->process($jobUid);

        self::assertSame([4711], $actor->uids, 'the job must run in exactly one runAs scope');
        self::assertTrue($generator->wasInsideScope, 'generation must happen inside the scope, not beside it');
    }

    public function testGeneratorsSeeTheGrantsOfTheJobOwner(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/CapabilityGrantUsers.csv');
        $jobUid    = $this->seedJob(11);
        $jobs      = $this->get(JobProcessingRepository::class);
        $generator = new RecordingArtifactGenerator($jobs);

        $orchestrator = new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $this->stubIngestion($this->stubDocument()),
            $this->stubAnalyzer($this->stubBrief()),
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->get(CapabilityGrantResolver::class),
            [$generator],
        );
        $orchestrator->process($jobUid);

        // be_user 11 is in a group granting generate_audio only; the per-generator
        // context (withProgress) must carry the grants too.
        self::assertInstanceOf(GenerationContext::class, $generator->seen);
        self::assertEquals(new CapabilityGrants(audio: true, vision: false), $generator->seen->grants);
    }

    public function testRunsWithoutAScopeWhenNoTechnicalActorIsConfigured(): void
    {
        $jobUid = $this->seedJob();
        $jobs   = $this->get(JobProcessingRepository::class);
        $actor  = new RecordingTechnicalActorContext();

        $orchestrator = new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $this->stubIngestion($this->stubDocument()),
            $this->stubAnalyzer($this->stubBrief()),
            $this->get(PromptSnippetResolver::class),
            $actor,
            $this->extensionConfigurationWithActorUid(0),
            $this->get(CapabilityGrantResolver::class),
            [new RecordingArtifactGenerator($jobs)],
        );
        $orchestrator->process($jobUid);

        self::assertSame([], $actor->uids, 'uid 0 must behave exactly as before this seam existed');
    }

    private function extensionConfigurationWithActorUid(int $uid): ExtensionConfiguration
    {
        // readonly: TYPO3 v14 declares ExtensionConfiguration readonly, and a
        // non-readonly class cannot extend one.
        return new readonly class ($uid) extends ExtensionConfiguration {
            public function __construct(private int $uid) {}

            public function get(string $extension, string $path = ''): mixed
            {
                return $path === 'technicalBeUserUid' ? $this->uid : null;
            }
        };
    }
}

/** Records every runAs() it is asked for and runs the callable, so the scope is observable. */
final class RecordingTechnicalActorContext implements TechnicalActorContextInterface
{
    /** @var list<int> */
    public array $uids = [];

    public bool $inside = false;

    public function runAs(int $beUserUid, callable $fn): mixed
    {
        $this->uids[] = $beUserUid;
        $this->inside = true;

        try {
            return $fn();
        } finally {
            $this->inside = false;
        }
    }

    public function getCurrentActor(): ?TechnicalActor
    {
        return null;
    }
}

/** Asserts, from inside generate(), that the surrounding runAs() scope is still open. */
final class ScopeAssertingArtifactGenerator implements ArtifactGeneratorInterface
{
    public bool $wasInsideScope = false;

    public function __construct(
        private readonly JobProcessingRepository $jobs,
        private readonly RecordingTechnicalActorContext $actor,
    ) {}

    public function supports(GenerationContext $ctx): bool
    {
        return true;
    }

    public function generate(GenerationContext $ctx): bool
    {
        $this->wasInsideScope = $this->actor->inside;
        $this->jobs->insertArtifact($ctx->jobUid(), ArtifactType::Stub, 'default', 0, ArtifactStatus::Done);

        return true;
    }
}

/**
 * Records the context it saw and inserts one Done artifact — shared by the orchestrator tests
 * so the stub is defined once (avoids duplicated test fixtures). It also reports one
 * mid-generation progress step and snapshots the job row right after, so the tests can
 * assert the fine-grained progress reporting end-to-end (real repository, real DB).
 */
final class RecordingArtifactGenerator implements ArtifactGeneratorInterface
{
    public ?GenerationContext $seen = null;

    /** @var array<string, mixed> job row snapshot taken right after the progress step */
    public array $rowAfterStep = [];

    public function __construct(private readonly JobProcessingRepository $jobs) {}

    public function supports(GenerationContext $ctx): bool
    {
        return true;
    }

    public function generate(GenerationContext $ctx): bool
    {
        $this->seen = $ctx;
        $ctx->progress?->step('Stub: halfway there', 0.5);
        $this->rowAfterStep = $this->jobs->findRow($ctx->jobUid()) ?? [];
        $this->jobs->insertArtifact($ctx->jobUid(), ArtifactType::Stub, 'default', 0, ArtifactStatus::Done);

        return true;
    }
}
