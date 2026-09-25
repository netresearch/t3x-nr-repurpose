<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Netresearch\NrLlm\Testing\FakeBudgetService;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrRepurpose\Domain\ValueObject\CapabilityGrants;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\FaqGenerator;
use Netresearch\NrRepurpose\Generator\Image\ImageGeneratorInterface;
use Netresearch\NrRepurpose\Generator\PodcastGenerator;
use Netresearch\NrRepurpose\Generator\SchaubildGenerator;
use Netresearch\NrRepurpose\Generator\Speech\SpeechSynthesizerInterface;
use Netresearch\NrRepurpose\Generator\Support\TextLabels;
use Netresearch\NrRepurpose\Generator\Support\WebVttBuilder;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\PromptSnippetResolver;
use Netresearch\NrRepurpose\Provenance\AiLabelSettingsFactory;
use Netresearch\NrRepurpose\Provenance\DigitalSourceType;
use Netresearch\NrRepurpose\Rendering\AudioStitcherInterface;
use Netresearch\NrRepurpose\Rendering\GdImageCompositor;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolverInterface;
use Netresearch\NrRepurpose\Service\GenerationOrchestrator;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\AiMarkerReader;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A whole job, real orchestrator, real generators, real Fluid templates, real FAL and
 * database: every stored artifact must carry the AI label (ADR-005). Only the model
 * calls and the two external binaries are replaced — the "renderer" returns a PNG that
 * the extension's Chromium renderer wrote, the "stitcher" the MP3 ffmpeg's concat wrote
 * (Tests/Fixtures), so the markers are embedded into real output bytes.
 *
 * Both visible labels are switched on in the extension configuration of this instance.
 */
final class AiLabelledJobTest extends AbstractFunctionalTestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/';

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_repurpose' => ['aiLabelImages' => '1', 'aiLabelTexts' => '1'],
        ],
    ];

    public function testEveryStoredArtifactOfAJobCarriesTheAiLabel(): void
    {
        $jobUid = $this->seedJob();
        $jobs   = $this->get(JobProcessingRepository::class);

        $completion                   = new FakeCompletionService();
        $completion->jsonResult       = ['turns' => [['speaker' => 'Host A', 'text' => 'Revenue grew.'], ['speaker' => 'Host B', 'text' => 'By twelve percent.']]];
        $completion->markdownResult   = '<p>Revenue +12 %</p>';
        $completion->structuredResult = ['faq' => [['question' => 'How much did revenue grow?', 'answer' => 'By twelve percent.']]];

        $renderer = $this->renderer();
        $storage  = $this->get(JobFileStorage::class);
        $budget   = new FakeBudgetService();
        $logger   = new NullLogger();
        $labels   = new TextLabels($this->get(LanguageServiceFactory::class));

        (new GenerationOrchestrator(
            $jobs,
            $logger,
            $this->ingestion(),
            $this->analyzer(),
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->grants(),
            $this->get(AiLabelSettingsFactory::class),
            [
                new PodcastGenerator($jobs, $budget, $logger, $completion, $this->speech(), $this->stitcher(), $storage, new WebVttBuilder()),
                new SchaubildGenerator($jobs, $budget, $logger, $completion, $renderer, new GdImageCompositor(), $this->unavailableImages(), $storage),
                new FaqGenerator($jobs, $budget, $logger, $completion, $labels),
            ],
        ))->process($jobUid);

        $rows = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->select(['type', 'variant', 'status', 'file_uid', 'subtitle_file_uid', 'script_text', 'metadata'], 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid, 'status' => 'done'])
            ->fetchAllAssociative();
        $done = [];
        foreach ($rows as $row) {
            $done[$row['type'] . '/' . $row['variant']] = $row;
        }

        // The image variants fail (no image service); the three rows that ran are done.
        self::assertSame(['faq/default', 'podcast/default', 'schaubild/html'], array_keys($this->sorted($done)));

        foreach ($done as $key => $row) {
            $label = json_decode((string) $row['metadata'], true)['aiLabel'] ?? null;
            self::assertIsArray($label, $key);
            self::assertTrue($label['aiGenerated'], $key);
            self::assertStringStartsWith('nr_repurpose', $label['generator'], $key);
        }

        // Podcast: the stored MP3 carries the ID3 frames; MP3 and subtitles the FAL description.
        $mp3 = $this->file((int) $done['podcast/default']['file_uid']);
        self::assertSame('true', AiMarkerReader::id3UserText(AiMarkerReader::id3($mp3['contents'])['frames'])['AI-generated'] ?? null);
        self::assertStringStartsWith('AI-generated with nr_repurpose', $mp3['description']);
        self::assertStringContainsString('trainedAlgorithmicMedia', $mp3['description']);
        self::assertStringStartsWith('AI-generated with nr_repurpose', $this->file((int) $done['podcast/default']['subtitle_file_uid'])['description']);

        // Schaubild: the stored PNG carries the XMP digital source type, the render the visible label.
        $png = $this->file((int) $done['schaubild/html']['file_uid']);
        $xmp = AiMarkerReader::pngXmp($png['contents']);
        self::assertNotNull($xmp);
        self::assertSame(DigitalSourceType::CompositeWithTrainedAlgorithmicMedia->value, AiMarkerReader::xmpDigitalSourceType($xmp));
        self::assertStringContainsString('compositeWithTrainedAlgorithmicMedia', $png['description']);
        self::assertStringContainsString('<div class="ai-label">AI-generated</div>', $renderer->html[0] ?? '');

        // FAQ: the copy-ready text ends with the closing line in the text's language.
        self::assertStringEndsWith("\n\nThis text was created with AI.", (string) $done['faq/default']['script_text']);
    }

    /**
     * @param array<string, mixed> $rows
     *
     * @return array<string, mixed>
     */
    private function sorted(array $rows): array
    {
        ksort($rows);

        return $rows;
    }

    /** @return array{contents: string, description: string} */
    private function file(int $uid): array
    {
        self::assertGreaterThan(0, $uid);
        $file = $this->get(ResourceFactory::class)->getFileObject($uid);
        $row  = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->select(['description'], 'sys_file_metadata', ['file' => $uid])
            ->fetchAssociative();

        return ['contents' => $file->getContents(), 'description' => (string) ($row['description'] ?? '')];
    }

    private function seedJob(): int
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid'               => 0, 'source_type' => 'url', 'source_value' => 'https://example.com/',
            'theme'             => 'nr', 'want_podcast' => 1, 'want_schaubild' => 1, 'want_story' => 0,
            'want_exec_summary' => 0, 'want_faq' => 1, 'want_social_post' => 0, 'want_newsletter' => 0,
            'status'            => 'queued',
        ]);

        return (int) $conn->lastInsertId();
    }

    private function ingestion(): SourceIngestionServiceInterface
    {
        return new class implements SourceIngestionServiceInterface {
            public function ingest(array $jobRow): SourceDocument
            {
                return new SourceDocument('Quarterly report', 'Revenue grew by twelve percent.', 'https://example.com/', 0, 'en');
            }
        };
    }

    private function analyzer(): DocumentAnalyzerInterface
    {
        return new class implements DocumentAnalyzerInterface {
            public function analyze(SourceDocument $document, array $jobRow): ContentBrief
            {
                return new ContentBrief('Quarterly report', 'Revenue grew.', ['Revenue +12 %'], [], 'Analysts', 'en');
            }
        };
    }

    private function grants(): CapabilityGrantResolverInterface
    {
        return new class implements CapabilityGrantResolverInterface {
            public function resolve(int $beUserUid): CapabilityGrants
            {
                return CapabilityGrants::all();
            }
        };
    }

    /** Returns the Chromium-rendered fixture and keeps the HTML it was asked to render. */
    private function renderer(): HtmlToImageRendererInterface
    {
        return new class (self::FIXTURES . 'Image/chromium-render.png') implements HtmlToImageRendererInterface {
            /** @var list<string> */
            public array $html = [];

            public function __construct(private readonly string $fixture) {}

            public function render(string $html, int $width, ?int $height, float $deviceScaleFactor = 1.0, bool $transparent = false): string
            {
                $this->html[] = $html;
                $out          = sys_get_temp_dir() . '/nrrepurpose_render_' . bin2hex(random_bytes(4)) . '.png';
                copy($this->fixture, $out);

                return $out;
            }
        };
    }

    /** Joins nothing: hands back the MP3 ffmpeg's concat wrote for two real segments. */
    private function stitcher(): AudioStitcherInterface
    {
        return new class (self::FIXTURES . 'Audio/stitched-podcast.mp3') implements AudioStitcherInterface {
            public function __construct(private readonly string $fixture) {}

            public function concat(array $mp3Paths, string $outPath): string
            {
                copy($this->fixture, $outPath);

                return $outPath;
            }

            public function probeDurationSeconds(string $path): float
            {
                return 1.0;
            }
        };
    }

    private function speech(): SpeechSynthesizerInterface
    {
        return new class implements SpeechSynthesizerInterface {
            public function isAvailable(): bool
            {
                return true;
            }

            public function getModel(): string
            {
                return 'tts-model-x';
            }

            public function synthesizeToFile(string $text, string $voice, string $outputPath): void
            {
                copy(__DIR__ . '/../../Fixtures/Audio/tts-segment-without-id3.mp3', $outputPath);
            }
        };
    }

    private function unavailableImages(): ImageGeneratorInterface
    {
        return new class implements ImageGeneratorInterface {
            public function isAvailable(): bool
            {
                return false;
            }

            public function getModel(): string
            {
                return 'image-model-x';
            }

            public function getPromptPreamble(): string
            {
                return '';
            }

            public function generateToFile(string $prompt, string $size, string $outputPath): void
            {
                // Never reached: isAvailable() is false, so the generator skips the call.
            }
        };
    }
}
