<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Pipeline\PromptSnippetResolver;
use Netresearch\NrRepurpose\Service\GenerationOrchestrator;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class GenerationOrchestratorTest extends AbstractFunctionalTestCase
{
    private function seedJob(): int
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid' => 0, 'source_type' => 'url', 'source_value' => 'https://example.com/',
            'theme' => 'nr', 'want_podcast' => 1, 'want_schaubild' => 1, 'want_story' => 1,
            'status' => 'queued', 'be_user' => 0,
        ]);

        return (int) $conn->lastInsertId();
    }

    private function stubDocument(string $title = 'Doc', string $text = 'Body.'): SourceDocument
    {
        return new SourceDocument(
            title: $title,
            text: $text,
            sourceLabel: 'https://example.com/',
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

        $document = $this->stubDocument('Quarterly report', 'Revenue grew across all regions.');
        $brief = $this->stubBrief('Quarterly report');

        $jobs = $this->get(JobProcessingRepository::class);
        $generator = new RecordingArtifactGenerator($jobs);

        $orchestrator = new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $this->stubIngestion($document),
            $this->stubAnalyzer($brief),
            $this->get(PromptSnippetResolver::class),
            [$generator],
        );
        $orchestrator->process($jobUid);

        self::assertInstanceOf(GenerationContext::class, $generator->seen);
        self::assertSame('nr', $generator->seen->theme);
        self::assertSame('Quarterly report', $generator->seen->brief->title);
        self::assertSame('Revenue grew across all regions.', $generator->seen->document->text);
        // A job without a prompt-snippet selection resolves to the empty default (real resolver).
        self::assertSame([], $generator->seen->snippets->personas);
        self::assertSame('', $generator->seen->snippets->schaubildSections);
        self::assertSame('', $generator->seen->snippets->storySections);

        $row = $jobs->findRow($jobUid);
        self::assertSame('done', $row['status']);
        self::assertSame(100, (int) $row['progress']);
        self::assertSame('en', $row['language_detected']);

        $artifactCount = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->count('uid', 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid, 'status' => 'done']);
        self::assertSame(1, $artifactCount);
    }

    public function testIngestionFailureMarksJobFailedAndRunsNoGenerator(): void
    {
        $jobUid = $this->seedJob();
        $jobs = $this->get(JobProcessingRepository::class);

        $ingestion = new class implements SourceIngestionServiceInterface {
            public function ingest(array $jobRow): SourceDocument
            {
                throw new \RuntimeException('source unreachable');
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

        $orchestrator = new GenerationOrchestrator($jobs, new NullLogger(), $ingestion, $analyzer, $this->get(PromptSnippetResolver::class), [$generator]);
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
        $jobs = $this->get(JobProcessingRepository::class);

        // A prior (interrupted) run left a stale artifact row for this job.
        $jobs->insertArtifact($jobUid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Failed);

        $orchestrator = new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $this->stubIngestion($this->stubDocument()),
            $this->stubAnalyzer($this->stubBrief()),
            $this->get(PromptSnippetResolver::class),
            [new RecordingArtifactGenerator($jobs)],
        );
        $orchestrator->process($jobUid);

        // The stale row is cleared before generation; only the fresh artifact remains (no duplicate).
        $total = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->count('uid', 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid]);
        self::assertSame(1, $total);
    }
}

/**
 * Records the context it saw and inserts one Done artifact — shared by the orchestrator tests
 * so the stub is defined once (avoids duplicated test fixtures).
 */
final class RecordingArtifactGenerator implements ArtifactGeneratorInterface
{
    public ?GenerationContext $seen = null;

    public function __construct(private readonly JobProcessingRepository $jobs) {}

    public function supports(GenerationContext $ctx): bool
    {
        return true;
    }

    public function generate(GenerationContext $ctx): bool
    {
        $this->seen = $ctx;
        $this->jobs->insertArtifact($ctx->jobUid(), ArtifactType::Stub, 'default', 0, ArtifactStatus::Done);

        return true;
    }
}
