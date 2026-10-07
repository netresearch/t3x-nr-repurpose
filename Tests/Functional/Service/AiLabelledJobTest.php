<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Netresearch\NrLlm\Testing\FakeBudgetService;
use Netresearch\NrRepurpose\Domain\ValueObject\CapabilityGrants;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\FaqGenerator;
use Netresearch\NrRepurpose\Generator\HandoutGenerator;
use Netresearch\NrRepurpose\Generator\Image\ImageGeneratorInterface;
use Netresearch\NrRepurpose\Generator\PodcastGenerator;
use Netresearch\NrRepurpose\Generator\SchaubildGenerator;
use Netresearch\NrRepurpose\Generator\SlideDeckGenerator;
use Netresearch\NrRepurpose\Generator\Speech\SpeechSynthesizerInterface;
use Netresearch\NrRepurpose\Generator\StoryGenerator;
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
use Netresearch\NrRepurpose\Rendering\HtmlToPdfRendererInterface;
use Netresearch\NrRepurpose\Rendering\SlideshowRendererInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolverInterface;
use Netresearch\NrRepurpose\Service\GenerationOrchestrator;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\AiMarkerReader;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\FakeTextCompletion;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

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
    private const string FIXTURES = __DIR__ . '/../../Fixtures/';

    public const string TITLE = 'Quarterly report';

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_repurpose' => ['aiLabelImages' => '1', 'aiLabelTexts' => '1'],
        ],
    ];

    public function testEveryStoredArtifactOfAJobCarriesTheAiLabel(): void
    {
        $jobUid = $this->seedJob();
        $jobs   = $this->get(JobProcessingRepository::class);

        $completion                   = new FakeTextCompletion();
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
                new SchaubildGenerator($jobs, $budget, $logger, $completion, $renderer, new GdImageCompositor(new NullLogger()), $this->unavailableImages(), $storage, $this->get(ViewFactoryInterface::class)),
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
        $vtt = $this->file((int) $done['podcast/default']['subtitle_file_uid']);
        self::assertStringStartsWith('AI-generated with nr_repurpose', $vtt['description']);
        self::assertStringStartsWith("WEBVTT\n\nNOTE AI-generated with nr_repurpose", $vtt['contents']);

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
     * A renderer that hands back something other than a PNG (here: a truncated one) must
     * not end up in FAL unlabelled: the artifact fails with a readable reason and no
     * file is written.
     */
    public function testAPngThatCannotBeLabelledFailsTheArtifactAndStoresNoFile(): void
    {
        $jobUid                     = $this->seedJob(podcast: false, faq: false);
        $jobs                       = $this->get(JobProcessingRepository::class);
        $completion                 = new FakeTextCompletion();
        $completion->markdownResult = '<p>Revenue +12 %</p>';

        $filesBefore = $this->fileCount();

        (new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $this->ingestion(),
            $this->analyzer(),
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->grants(),
            $this->get(AiLabelSettingsFactory::class),
            [new SchaubildGenerator($jobs, new FakeBudgetService(), new NullLogger(), $completion, $this->renderer(40), new GdImageCompositor(new NullLogger()), $this->unavailableImages(), $this->get(JobFileStorage::class), $this->get(ViewFactoryInterface::class))],
        ))->process($jobUid);

        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->select(['status', 'file_uid', 'error_message'], 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid, 'variant' => 'html'])
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame('failed', $row['status']);
        self::assertSame(0, (int) $row['file_uid']);
        self::assertSame('Schaubild html variant error: Cannot AI-label the file: it is not a complete PNG', $row['error_message']);
        self::assertSame($filesBefore, $this->fileCount());
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function documentFormats(): iterable
    {
        yield 'slide deck' => ['slide_deck', 'want_slide_deck', [
            'title'    => 'Quarterly <b>report</b>',
            'subtitle' => 'Q3 at a glance',
            'slides'   => [['heading' => 'Revenue', 'bullets' => ['Up by twelve percent', 'Driven by <script>x</script> exports']]],
            'takeaway' => 'Growth continues.',
        ]];
        yield 'handout' => ['handout', 'want_handout', [
            'title'    => 'Quarterly <b>report</b>',
            'lead'     => 'Revenue grew by twelve percent.',
            'sections' => [['heading' => 'Revenue', 'paragraphs' => ['Driven by <script>x</script> exports.']]],
            'keyFacts' => ['Revenue +12 %'],
        ]];
    }

    /**
     * A document format renders its real Fluid template with the LLM output escaped,
     * prints it (here: the Chromium PDF fixture stands in for the print) and stores a
     * PDF that carries the AI entries and the FAL description; the template prints the
     * closing line (aiLabelTexts is on in this instance).
     *
     * @param array<string, mixed> $answer
     */
    #[DataProvider('documentFormats')]
    public function testADocumentFormatStoresALabelledPdf(string $type, string $column, array $answer): void
    {
        $jobUid                       = $this->seedJob(podcast: false, faq: false, schaubild: false, document: $column);
        $jobs                         = $this->get(JobProcessingRepository::class);
        $completion                   = new FakeTextCompletion();
        $completion->structuredResult = $answer;

        $printer = $this->printer();
        $storage = $this->get(JobFileStorage::class);
        $labels  = new TextLabels($this->get(LanguageServiceFactory::class));

        $generator = $type === 'slide_deck'
            ? new SlideDeckGenerator($jobs, new FakeBudgetService(), new NullLogger(), $completion, $printer, $storage, $this->get(ViewFactoryInterface::class))
            : new HandoutGenerator($jobs, new FakeBudgetService(), new NullLogger(), $completion, $printer, $storage, $this->get(ViewFactoryInterface::class), $labels);

        (new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $this->ingestion(),
            $this->analyzer(),
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->grants(),
            $this->get(AiLabelSettingsFactory::class),
            [$generator],
        ))->process($jobUid);

        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->select(['status', 'file_uid', 'source_html', 'error_message'], 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid, 'type' => $type])
            ->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame('done', $row['status'], (string) $row['error_message']);

        $html = $printer->html[0] ?? '';
        self::assertSame($html, $row['source_html']);
        self::assertStringContainsString('Quarterly &lt;b&gt;report&lt;/b&gt;', $html);
        // Every occurrence escaped: no raw markup from the model anywhere in the document.
        self::assertStringNotContainsString('<b>report</b>', $html);
        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('This text was created with AI.', $html);
        self::assertStringContainsString('<html lang="en">', $html);

        $pdf = $this->file((int) $row['file_uid']);
        self::assertStringStartsWith('%PDF-1.4', $pdf['contents']);
        self::assertStringContainsString('/AIGenerated (true)', $pdf['contents']);
        self::assertStringContainsString(DigitalSourceType::TrainedAlgorithmicMedia->value, $pdf['contents']);
        self::assertStringStartsWith('AI-generated with nr_repurpose', $pdf['description']);
    }

    /**
     * The story with the video option: the slides are rendered (the Chromium PNG
     * fixture stands in), the "slideshow" returns the MP4 ffmpeg 8.1 made from three
     * slides, and the video is stored in FAL with the AI statement as its description.
     * The MP4 bytes are stored as they came: ffmpeg writes the marker keys itself, and
     * the keys it is asked for are asserted in StoryGeneratorTest.
     */
    public function testTheStoryVideoIsStoredWithTheAiDescription(): void
    {
        $jobUid = $this->seedJob(podcast: false, faq: false, schaubild: false, document: 'want_story');
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->update('tx_nrrepurpose_domain_model_job', ['want_video' => 1], ['uid' => $jobUid]);
        $jobs                   = $this->get(JobProcessingRepository::class);
        $completion             = new FakeTextCompletion();
        $completion->jsonResult = ['slides' => [
            ['role' => 'cover', 'headline' => self::TITLE, 'subline' => 'Q3'],
            ['role' => 'outro', 'headline' => 'Growth continues', 'subline' => 'example.com'],
        ]];
        $slideshow = new class (self::FIXTURES . 'Video/slideshow.mp4') implements SlideshowRendererInterface {
            public int $images = 0;

            public function __construct(private readonly string $fixture) {}

            public function render(array $imagePaths, int $width, int $height, float $secondsPerImage, array $metadata): string
            {
                $this->images = count($imagePaths);
                $out          = sys_get_temp_dir() . '/nrrepurpose_video_' . bin2hex(random_bytes(4)) . '.mp4';
                copy($this->fixture, $out);

                return $out;
            }
        };

        (new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $this->ingestion(),
            $this->analyzer(),
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->grants(),
            $this->get(AiLabelSettingsFactory::class),
            [new StoryGenerator($jobs, new FakeBudgetService(), new NullLogger(), $completion, $this->renderer(), new GdImageCompositor(new NullLogger()), $this->unavailableImages(), $this->get(JobFileStorage::class), $this->get(ViewFactoryInterface::class), $slideshow)],
        ))->process($jobUid);

        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->select(['status', 'file_uid', 'metadata', 'error_message'], 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid, 'type' => 'video'])
            ->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame('done', $row['status'], (string) $row['error_message']);
        self::assertSame(2, $slideshow->images);
        self::assertStringEndsWith('compositeWithTrainedAlgorithmicMedia', json_decode((string) $row['metadata'], true)['aiLabel']['digitalSourceType']);

        $video = $this->file((int) $row['file_uid']);
        self::assertSame((string) file_get_contents(self::FIXTURES . 'Video/slideshow.mp4'), $video['contents']);
        self::assertStringStartsWith('AI-generated with nr_repurpose', $video['description']);
    }

    private function fileCount(): int
    {
        return (int) GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->count('uid', 'sys_file', []);
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

    private function seedJob(bool $podcast = true, bool $faq = true, bool $schaubild = true, ?string $document = null): int
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $row  = [
            'pid'               => 0, 'source_type' => 'url', 'source_value' => 'https://example.com/',
            'theme'             => 'nr', 'want_podcast' => (int) $podcast, 'want_schaubild' => (int) $schaubild, 'want_story' => 0,
            'want_exec_summary' => 0, 'want_faq' => (int) $faq, 'want_social_post' => 0, 'want_newsletter' => 0,
            'status'            => 'queued',
        ];
        if ($document !== null) {
            $row[$document] = 1;
        }

        $conn->insert('tx_nrrepurpose_domain_model_job', $row);

        return (int) $conn->lastInsertId();
    }

    private function ingestion(): SourceIngestionServiceInterface
    {
        return new class implements SourceIngestionServiceInterface {
            public function ingest(JobSnapshot $job): SourceDocument
            {
                return new SourceDocument(AiLabelledJobTest::TITLE, 'Revenue grew by twelve percent.', 'https://example.com/', 0, 'en');
            }
        };
    }

    private function analyzer(): DocumentAnalyzerInterface
    {
        return new class implements DocumentAnalyzerInterface {
            public function analyze(SourceDocument $document, JobSnapshot $job): ContentBrief
            {
                return new ContentBrief(AiLabelledJobTest::TITLE, 'Revenue grew.', ['Revenue +12 %'], [], 'Analysts', 'en');
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

    /**
     * Stands in for Chromium's print: keeps the HTML and hands back a copy of the PDF
     * Chromium printed (Tests/Fixtures/Document/chromium-deck.pdf).
     */
    private function printer(): HtmlToPdfRendererInterface
    {
        return new class (self::FIXTURES . 'Document/chromium-deck.pdf') implements HtmlToPdfRendererInterface {
            /** @var list<string> */
            public array $html = [];

            public function __construct(private readonly string $fixture) {}

            public function renderPdf(string $html, int $viewportWidth): string
            {
                $this->html[] = $html;
                $out          = sys_get_temp_dir() . '/nrrepurpose_print_' . bin2hex(random_bytes(4)) . '.pdf';
                copy($this->fixture, $out);

                return $out;
            }
        };
    }

    /**
     * Returns the Chromium-rendered fixture — its first $truncateTo bytes when given — and
     * keeps the HTML it was asked to render.
     */
    private function renderer(?int $truncateTo = null): HtmlToImageRendererInterface
    {
        return new class (self::FIXTURES . 'Image/chromium-render.png', $truncateTo) implements HtmlToImageRendererInterface {
            /** @var list<string> */
            public array $html = [];

            public function __construct(private readonly string $fixture, private readonly ?int $truncateTo) {}

            public function render(string $html, int $width, ?int $height, float $deviceScaleFactor = 1.0, bool $transparent = false): string
            {
                $this->html[] = $html;
                $out          = sys_get_temp_dir() . '/nrrepurpose_render_' . bin2hex(random_bytes(4)) . '.png';
                $bytes        = (string) file_get_contents($this->fixture);
                file_put_contents($out, $this->truncateTo === null ? $bytes : substr($bytes, 0, $this->truncateTo));

                return $out;
            }
        };
    }

    /** Joins nothing: hands back the MP3 ffmpeg's concat wrote for two real segments. */
    private function stitcher(): AudioStitcherInterface
    {
        return new readonly class (self::FIXTURES . 'Audio/stitched-podcast.mp3') implements AudioStitcherInterface {
            public function __construct(private string $fixture) {}

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
