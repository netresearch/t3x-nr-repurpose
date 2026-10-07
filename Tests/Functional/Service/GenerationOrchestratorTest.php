<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use GuzzleHttp\Psr7\HttpFactory;
use Netresearch\NrLlm\Testing\FakeBudgetService;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\ValueObject\CapabilityGrants;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Exception\MalformedJobRowException;
use Netresearch\NrRepurpose\Generator\AbstractGenerator;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Generator\ExecutiveSummaryGenerator;
use Netresearch\NrRepurpose\Generator\FaqGenerator;
use Netresearch\NrRepurpose\Generator\NewsletterGenerator;
use Netresearch\NrRepurpose\Generator\SocialPostGenerator;
use Netresearch\NrRepurpose\Generator\Support\TextLabels;
use Netresearch\NrRepurpose\Generator\Support\TextLimiter;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\PdfLayoutExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfTextExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfVisionExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\SymfonyProcessPopplerRunner;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionService;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Pipeline\JobProgress;
use Netresearch\NrRepurpose\Pipeline\PromptSnippetResolver;
use Netresearch\NrRepurpose\Provenance\AiLabelSettingsFactory;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolver;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolverInterface;
use Netresearch\NrRepurpose\Service\GenerationOrchestrator;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\FakeTextCompletion;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\QueuedHttpClient;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StaticHostResolver;
use Netresearch\NrRepurpose\Understanding\AnalysisException;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use Netresearch\NrVault\Security\TechnicalActor;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class GenerationOrchestratorTest extends AbstractFunctionalTestCase
{
    private const string QUARTERLY_REPORT = 'Quarterly report';

    private const string SOURCE_URL = 'https://example.com/';

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
        return new readonly class ($document) implements SourceIngestionServiceInterface {
            public function __construct(private SourceDocument $document) {}

            public function ingest(JobSnapshot $job): SourceDocument
            {
                return $this->document;
            }
        };
    }

    private function stubAnalyzer(ContentBrief $brief): DocumentAnalyzerInterface
    {
        return new readonly class ($brief) implements DocumentAnalyzerInterface {
            public function __construct(private ContentBrief $brief) {}

            public function analyze(SourceDocument $document, JobSnapshot $job): ContentBrief
            {
                return $this->brief;
            }
        };
    }

    /**
     * The temp directories a generator makes (the story slides, the podcast segments)
     * are removed once the generator is done — after a success and after a throw.
     */
    public function testTempDirectoriesAGeneratorLeavesAreRemovedAfterItRuns(): void
    {
        $jobs      = $this->get(JobProcessingRepository::class);
        $leaving   = new TempDirLeavingGenerator($jobs, new FakeBudgetService(), false);
        $throwing  = new TempDirLeavingGenerator($jobs, new FakeBudgetService(), true);
        $ingestion = $this->stubIngestion($this->stubDocument());
        $analyzer  = $this->stubAnalyzer($this->stubBrief());

        foreach ([$leaving, $throwing] as $generator) {
            $orchestrator = new GenerationOrchestrator(
                $jobs,
                new NullLogger(),
                $ingestion,
                $analyzer,
                $this->get(PromptSnippetResolver::class),
                $this->get(TechnicalActorContextInterface::class),
                $this->get(ExtensionConfiguration::class),
                $this->get(CapabilityGrantResolver::class),
                $this->get(AiLabelSettingsFactory::class),
                [$generator],
            );
            try {
                $orchestrator->process($this->seedJob());
            } catch (RuntimeException) {
                // The throwing generator's exception passes through the orchestrator.
            }

            self::assertNotNull($generator->dir);
            self::assertDirectoryDoesNotExist($generator->dir);
        }
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
            $this->get(AiLabelSettingsFactory::class),
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
        $completion                   = new FakeTextCompletion();
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
            $this->get(AiLabelSettingsFactory::class),
            [
                new ExecutiveSummaryGenerator($jobs, $budget, $logger, $completion),
                new FaqGenerator($jobs, $budget, $logger, $completion, new TextLabels($this->get(LanguageServiceFactory::class))),
                new SocialPostGenerator($jobs, $budget, $logger, $completion, new TextLimiter()),
                new NewsletterGenerator($jobs, $budget, $logger, $completion, new TextLabels($this->get(LanguageServiceFactory::class))),
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

    /**
     * A row written without the text-format columns (as by a script or an older form)
     * takes the database defaults: no text generator runs and no LLM call is made.
     */
    public function testAJobRowWithoutTextFlagsRunsNoTextGenerator(): void
    {
        $jobUid     = $this->seedJob();
        $jobs       = $this->get(JobProcessingRepository::class);
        $completion = new FakeTextCompletion();
        $budget     = new FakeBudgetService();
        $logger     = new NullLogger();

        $orchestrator = new GenerationOrchestrator(
            $jobs,
            $logger,
            $this->stubIngestion($this->stubDocument()),
            $this->stubAnalyzer($this->stubBrief()),
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->get(CapabilityGrantResolver::class),
            $this->get(AiLabelSettingsFactory::class),
            [
                new ExecutiveSummaryGenerator($jobs, $budget, $logger, $completion),
                new FaqGenerator($jobs, $budget, $logger, $completion, new TextLabels($this->get(LanguageServiceFactory::class))),
                new SocialPostGenerator($jobs, $budget, $logger, $completion, new TextLimiter()),
                new NewsletterGenerator($jobs, $budget, $logger, $completion, new TextLabels($this->get(LanguageServiceFactory::class))),
            ],
        );
        $orchestrator->process($jobUid);

        self::assertSame([], $completion->completeStructuredCalls);
        $artifacts = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->count('uid', 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid]);
        self::assertSame(0, $artifacts);
    }

    public function testIngestionFailureMarksJobFailedAndRunsNoGenerator(): void
    {
        $jobUid = $this->seedJob();
        $jobs   = $this->get(JobProcessingRepository::class);

        $ingestion = new class implements SourceIngestionServiceInterface {
            public function ingest(JobSnapshot $job): SourceDocument
            {
                // The real contract: SourceIngestionServiceInterface::ingest() throws
                // IngestionException on an unreachable source.
                throw new IngestionException('source unreachable');
            }
        };
        $analyzer = new class implements DocumentAnalyzerInterface {
            public bool $called = false;

            public function analyze(SourceDocument $document, JobSnapshot $job): ContentBrief
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

        $orchestrator = new GenerationOrchestrator($jobs, new NullLogger(), $ingestion, $analyzer, $this->get(PromptSnippetResolver::class), $this->get(TechnicalActorContextInterface::class), $this->get(ExtensionConfiguration::class), $this->get(CapabilityGrantResolver::class), $this->get(AiLabelSettingsFactory::class), [$generator]);
        $orchestrator->process($jobUid);

        $row = $jobs->findRow($jobUid);
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('source unreachable', (string) $row['error_message']);
        self::assertFalse($analyzer->called);
        self::assertFalse($generator->called);
    }

    /**
     * JobSnapshot::fromRow() types the row once, before ingestion: a source_type outside
     * the enum fails the job there, with a fixed message, and the exception is logged.
     */
    public function testAMalformedJobRowFailsTheJobBeforeIngestion(): void
    {
        $jobUid = $this->seedJob();
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->update('tx_nrrepurpose_domain_model_job', ['source_type' => 'docx'], ['uid' => $jobUid]);
        $jobs   = $this->get(JobProcessingRepository::class);
        $logger = new RecordingLogger();

        $ingestion = new class implements SourceIngestionServiceInterface {
            public bool $called = false;

            public function ingest(JobSnapshot $job): SourceDocument
            {
                $this->called = true;

                return new SourceDocument('t', 'b', 's', 0, 'en');
            }
        };

        (new GenerationOrchestrator($jobs, $logger, $ingestion, $this->stubAnalyzer($this->stubBrief()), $this->get(PromptSnippetResolver::class), $this->get(TechnicalActorContextInterface::class), $this->get(ExtensionConfiguration::class), $this->get(CapabilityGrantResolver::class), $this->get(AiLabelSettingsFactory::class), []))->process($jobUid);

        $row = $jobs->findRow($jobUid);
        self::assertSame('failed', $row['status'] ?? null);
        self::assertSame('Reading the job failed', $row['error_message'] ?? null);
        self::assertFalse($ingestion->called);
        $logged = array_map(static fn (array $record): mixed => $record['context']['exception'] ?? null, $logger->records);
        self::assertContainsOnlyInstancesOf(MalformedJobRowException::class, array_filter($logged));
        self::assertNotSame([], array_filter($logged));
    }

    /**
     * The terminal-status check reads the raw status before the row is parsed: a
     * finished job whose row no longer parses is left as it is on a redelivery.
     */
    public function testAFinishedJobWithAMalformedRowIsLeftAsItIs(): void
    {
        $jobUid = $this->seedJob();
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->update('tx_nrrepurpose_domain_model_job', ['status' => 'done', 'source_type' => 'docx', 'error_message' => ''], ['uid' => $jobUid]);
        $jobs = $this->get(JobProcessingRepository::class);

        $ingestion = new class implements SourceIngestionServiceInterface {
            public bool $called = false;

            public function ingest(JobSnapshot $job): SourceDocument
            {
                $this->called = true;

                return new SourceDocument('t', 'b', 's', 0, 'en');
            }
        };

        (new GenerationOrchestrator($jobs, new RecordingLogger(), $ingestion, $this->stubAnalyzer($this->stubBrief()), $this->get(PromptSnippetResolver::class), $this->get(TechnicalActorContextInterface::class), $this->get(ExtensionConfiguration::class), $this->get(CapabilityGrantResolver::class), $this->get(AiLabelSettingsFactory::class), []))->process($jobUid);

        $row = $jobs->findRow($jobUid);
        self::assertSame('done', $row['status'] ?? null);
        self::assertSame('', $row['error_message'] ?? null);
        self::assertFalse($ingestion->called);
    }

    /**
     * @return array<string, array{0: string, 1: Throwable, 2: string}> failing step, exception, stored error_message
     */
    public static function jobStepFailures(): array
    {
        $foreign = 'Provider answered 401 for https://api.example.test/v1 with key sk-secret; /var/www/html/var/transient/x.pdf';

        return [
            'ingestion, own message'         => ['ingestion', new IngestionException('PDF URL returned HTTP 404: https://example.com/a.pdf'), 'PDF URL returned HTTP 404: https://example.com/a.pdf'],
            'ingestion, foreign exception'   => ['ingestion', new RuntimeException($foreign), 'Ingestion failed'],
            'analysis, own message'          => ['analysis', new AnalysisException('Cannot analyze an empty source document'), 'Cannot analyze an empty source document'],
            'analysis, text model exception' => ['analysis', new RuntimeException($foreign), 'Analysis failed'],
        ];
    }

    /**
     * The job's error_message is shown to every module user. The extension's own ingestion
     * and analysis messages are written for it and stay; any other exception (the text model,
     * Guzzle, poppler, the database) can carry provider detail, paths or SQL: the row gets
     * "<step> failed" and the exception goes to the log.
     */
    #[DataProvider('jobStepFailures')]
    public function testAFailedJobStepStoresOnlyTheExtensionsOwnMessage(string $step, Throwable $cause, string $expected): void
    {
        $jobUid = $this->seedJob();
        $jobs   = $this->get(JobProcessingRepository::class);
        $logger = new RecordingLogger();

        $ingestion = $step === 'ingestion'
            ? new readonly class ($cause) implements SourceIngestionServiceInterface {
                public function __construct(private Throwable $cause) {}

                public function ingest(JobSnapshot $job): SourceDocument
                {
                    throw $this->cause;
                }
            }
        : $this->stubIngestion($this->stubDocument());
        $analyzer = new readonly class ($cause) implements DocumentAnalyzerInterface {
            public function __construct(private Throwable $cause) {}

            public function analyze(SourceDocument $document, JobSnapshot $job): ContentBrief
            {
                throw $this->cause;
            }
        };

        (new GenerationOrchestrator($jobs, $logger, $ingestion, $analyzer, $this->get(PromptSnippetResolver::class), $this->get(TechnicalActorContextInterface::class), $this->get(ExtensionConfiguration::class), $this->get(CapabilityGrantResolver::class), $this->get(AiLabelSettingsFactory::class), []))->process($jobUid);

        $row = $jobs->findRow($jobUid);
        self::assertSame('failed', $row['status']);
        self::assertSame($expected, $row['error_message']);
        self::assertContains($cause, array_map(static fn (array $record): mixed => $record['context']['exception'] ?? null, $logger->records));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> source_type, source_value, stored error_message
     */
    public static function sourceUrlsWithCredentials(): array
    {
        $url = 'https://user:secret@example.com/doc.pdf?token=abc#frag';

        return [
            'web page answers 404' => ['url', $url, 'URL returned HTTP 404: https://example.com/doc.pdf'],
            'PDF URL answers 404'  => ['pdf_url', $url, 'PDF URL returned HTTP 404: https://example.com/doc.pdf'],
            'scheme refused'       => [
                'url',
                'ftp://user:secret@example.com/doc.pdf?token=abc#frag',
                'Source URL scheme "ftp" is not allowed, only http and https: ftp://example.com/doc.pdf',
            ],
        ];
    }

    /**
     * The editor's source URL can carry a user name, a password and a query token. The
     * error_message a failed ingestion stores is shown to every module user, so it names the
     * URL without them. Real ingestion service, fetchers and guard; only the transport is faked.
     */
    #[DataProvider('sourceUrlsWithCredentials')]
    public function testAFailedIngestionStoresTheSourceUrlWithoutCredentialsOrQuery(string $sourceType, string $sourceValue, string $expected): void
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid'    => 0, 'source_type' => $sourceType, 'source_value' => $sourceValue,
            'theme'  => 'nr', 'want_podcast' => 0, 'want_schaubild' => 0, 'want_story' => 0,
            'status' => 'queued',
        ]);
        $jobUid = (int) $conn->lastInsertId();

        $client    = QueuedHttpClient::answering(404, 'Not found')->client;
        $factory   = new HttpFactory();
        $ingestion = new SourceIngestionService(
            new WebPageFetcher($client, $factory, StaticHostResolver::publicGuard()),
            new PdfFileResolver($this->get(FileRepository::class), $client, $factory, StaticHostResolver::publicGuard()),
            new PdfTextExtractor(),
            $this->createStub(PdfVisionExtractor::class),
            new PdfLayoutExtractor(new SymfonyProcessPopplerRunner(new NullLogger())),
            $this->get(CapabilityGrantResolverInterface::class),
            new NullLogger(),
        );
        $jobs = $this->get(JobProcessingRepository::class);

        (new GenerationOrchestrator($jobs, new NullLogger(), $ingestion, $this->stubAnalyzer($this->stubBrief()), $this->get(PromptSnippetResolver::class), $this->get(TechnicalActorContextInterface::class), $this->get(ExtensionConfiguration::class), $this->get(CapabilityGrantResolver::class), $this->get(AiLabelSettingsFactory::class), []))->process($jobUid);

        $error = (string) ($jobs->findRow($jobUid)['error_message'] ?? '');
        // The positive half: the job failed on the fetcher's own message, not on a foreign
        // exception that failJob() would have replaced with "Ingestion failed".
        self::assertSame($expected, $error);
        foreach (['secret', 'token=abc', 'user:', 'frag'] as $leak) {
            self::assertStringNotContainsString($leak, $error);
        }
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
            $this->get(AiLabelSettingsFactory::class),
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
     * Generators as [succeeds, artifact rows], final status, stored error_message, artifacts counter.
     *
     * @return array<string, array{0: list<array{0: bool, 1: int}>, 1: string, 2: string, 3: int}>
     */
    public static function jobOutcomes(): array
    {
        return [
            'every format fails'       => [[[false, 1], [false, 2]], 'failed', 'All 2 formats failed; each artifact shows its error', 3],
            'one of two formats fails' => [[[true, 1], [false, 1]], 'partially_done', '1 of 2 formats failed; each failed artifact shows its error', 2],
            'every format succeeds'    => [[[true, 3], [true, 1]], 'done', '', 4],
        ];
    }

    /**
     * The job row says why a run failed and counts its artifact rows (#77): the
     * artifacts carry their own errors, the job's error_message names how many formats
     * failed, and the artifacts counter matches the rows. A message from an earlier run
     * does not survive a later one.
     *
     * @param list<array{0: bool, 1: int}> $generators
     */
    #[DataProvider('jobOutcomes')]
    public function testTheFinishedJobNamesItsFailedFormatsAndCountsItsArtifacts(array $generators, string $status, string $error, int $artifacts): void
    {
        $jobUid = $this->seedJob();
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->update('tx_nrrepurpose_domain_model_job', ['error_message' => 'left over from an earlier run', 'artifacts' => 7], ['uid' => $jobUid]);
        $jobs = $this->get(JobProcessingRepository::class);

        (new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $this->stubIngestion($this->stubDocument()),
            $this->stubAnalyzer($this->stubBrief()),
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->get(CapabilityGrantResolver::class),
            $this->get(AiLabelSettingsFactory::class),
            array_map(static fn (array $g): OutcomeArtifactGenerator => new OutcomeArtifactGenerator($jobs, $g[0], $g[1]), $generators),
        ))->process($jobUid);

        $row = $jobs->findRow($jobUid);
        self::assertSame($status, $row['status'] ?? null);
        self::assertSame($error, $row['error_message'] ?? null);
        self::assertSame($artifacts, (int) ($row['artifacts'] ?? -1));
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
            $this->get(AiLabelSettingsFactory::class),
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
            $this->get(AiLabelSettingsFactory::class),
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
            $this->get(AiLabelSettingsFactory::class),
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

/** Writes a given number of artifact rows and reports success or failure. */
final readonly class OutcomeArtifactGenerator implements ArtifactGeneratorInterface
{
    public function __construct(
        private JobProcessingRepository $jobs,
        private bool $succeeds,
        private int $rows,
    ) {}

    public function supports(GenerationContext $ctx): bool
    {
        return true;
    }

    public function generate(GenerationContext $ctx): bool
    {
        for ($i = 0; $i < $this->rows; ++$i) {
            $this->jobs->insertArtifact(
                $ctx->jobUid(),
                ArtifactType::Stub,
                'v' . $i,
                0,
                $this->succeeds ? ArtifactStatus::Done : ArtifactStatus::Failed,
                $this->succeeds ? null : 'Stub failed',
            );
        }

        return $this->succeeds;
    }
}

/** Makes a temp directory with a file in it and leaves it behind; optionally throws. */
final class TempDirLeavingGenerator extends AbstractGenerator
{
    public ?string $dir = null;

    public function __construct(JobProcessingRepository $jobs, FakeBudgetService $budget, private readonly bool $throw)
    {
        parent::__construct($jobs, $budget, new NullLogger());
    }

    public function supports(GenerationContext $ctx): bool
    {
        return true;
    }

    public function generate(GenerationContext $ctx): bool
    {
        $this->dir = $this->makeTempDir();
        file_put_contents($this->dir . '/segment.mp3', 'MP3');
        if ($this->throw) {
            throw new RuntimeException('generator failed');
        }

        return true;
    }
}
