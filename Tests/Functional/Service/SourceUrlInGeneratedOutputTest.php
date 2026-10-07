<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use GuzzleHttp\Psr7\HttpFactory;
use LogicException;
use Netresearch\NrLlm\Testing\FakeBudgetService;
use Netresearch\NrRepurpose\Domain\ValueObject\CapabilityGrants;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Generator\FaqGenerator;
use Netresearch\NrRepurpose\Generator\HandoutGenerator;
use Netresearch\NrRepurpose\Generator\Image\ImageGeneratorInterface;
use Netresearch\NrRepurpose\Generator\SlideDeckGenerator;
use Netresearch\NrRepurpose\Generator\StoryGenerator;
use Netresearch\NrRepurpose\Generator\Support\TextLabels;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\PdfLayoutExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfTextExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfVisionExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\SymfonyProcessPopplerRunner;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionService;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\PromptSnippetResolver;
use Netresearch\NrRepurpose\Provenance\AiLabelSettingsFactory;
use Netresearch\NrRepurpose\Rendering\GdImageCompositor;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\HtmlToPdfRendererInterface;
use Netresearch\NrRepurpose\Rendering\SlideshowRendererInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolverInterface;
use Netresearch\NrRepurpose\Service\GenerationOrchestrator;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\FakeTextCompletion;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\QueuedHttpClient;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StaticHostResolver;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzer;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * The editor's source URL can carry a user name, a password and a query token, which the
 * fetch needs. The document label derived from it goes into the analysis and generation
 * prompts (sent to the text model) and into the templates of the story, the slide deck
 * and the handout (printed on published output). A whole job with the real ingestion,
 * the real analyzer and the real generators and templates; the model, the HTTP transport
 * and the Chromium renderer are faked and record what they were given.
 */
final class SourceUrlInGeneratedOutputTest extends AbstractFunctionalTestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/';

    private const string SOURCE = 'https://user:secret@example.com/doc?token=abc#frag';

    private const string SHOWN = 'https://example.com/doc';

    /** @return array<string, array{string, string}> generator, theme */
    public static function outputs(): array
    {
        return [
            'FAQ'                     => ['faq', 'nr'],
            'story, Netresearch'      => ['story', 'nr'],
            'story, Neutral'          => ['story', 'neutral'],
            'slide deck, Netresearch' => ['slide_deck', 'nr'],
            'slide deck, Neutral'     => ['slide_deck', 'neutral'],
            'handout, Netresearch'    => ['handout', 'nr'],
            'handout, Neutral'        => ['handout', 'neutral'],
        ];
    }

    #[DataProvider('outputs')]
    public function testNeitherPromptsNorTemplatesCarryTheCredentialsOrTheQuery(string $kind, string $theme): void
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid'               => 0, 'source_type' => 'url', 'source_value' => self::SOURCE, 'theme' => $theme,
            'want_podcast'      => 0, 'want_schaubild' => 0, 'want_story' => (int) ($kind === 'story'),
            'want_exec_summary' => 0, 'want_faq' => (int) ($kind === 'faq'), 'want_social_post' => 0, 'want_newsletter' => 0,
            'want_slide_deck'   => (int) ($kind === 'slide_deck'), 'want_handout' => (int) ($kind === 'handout'),
            'status'            => 'queued',
        ]);
        $jobUid = (int) $conn->lastInsertId();

        $analysis             = new FakeTextCompletion();
        $analysis->jsonResult = ['title' => 'Quarterly Results 2026', 'summary' => 'Revenue grew.', 'keyPoints' => ['Revenue +12 %'], 'sections' => [], 'audience' => 'Analysts', 'language' => 'en'];

        $generation = $this->generationCompletion($kind);
        $renderer   = $this->renderer();
        $printer    = $this->printer();
        $jobs       = $this->get(JobProcessingRepository::class);
        $client     = QueuedHttpClient::answering(200, (string) file_get_contents(self::FIXTURES . 'Web/article.html'))->client;
        $factory    = new HttpFactory();
        $ingestion  = new SourceIngestionService(
            new WebPageFetcher($client, $factory, StaticHostResolver::publicGuard()),
            new PdfFileResolver($this->get(FileRepository::class), $client, $factory, StaticHostResolver::publicGuard()),
            new PdfTextExtractor(),
            $this->createStub(PdfVisionExtractor::class),
            new PdfLayoutExtractor(new SymfonyProcessPopplerRunner(new NullLogger())),
            $this->grants(),
            new NullLogger(),
        );

        (new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $ingestion,
            new DocumentAnalyzer($analysis, new NullLogger()),
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->grants(),
            $this->get(AiLabelSettingsFactory::class),
            [$this->generator($kind, $generation, $renderer, $printer)],
        ))->process($jobUid);

        $artifacts = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->select(['status', 'error_message', 'script_text', 'source_html', 'metadata'], 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid])
            ->fetchAllAssociative();
        self::assertNotSame([], $artifacts);
        foreach ($artifacts as $artifact) {
            self::assertSame('done', $artifact['status'], (string) $artifact['error_message']);
        }

        $prompts = json_encode([$analysis->completeJsonCalls, $generation->completeJsonCalls, $generation->completeStructuredCalls], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $html    = implode("\n", [...$renderer->html, ...$printer->html]);
        $stored  = json_encode($artifacts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        // The positive half: the label does reach the prompts, and the rendered templates
        // of the formats that print it, so the absence below is not the absence of a label.
        self::assertStringContainsString(self::SHOWN, $prompts);
        if ($kind !== 'faq') {
            self::assertStringContainsString(self::SHOWN, $html);
        }

        foreach (['secret', 'token=abc', 'user:', 'frag'] as $leak) {
            self::assertStringNotContainsString($leak, $prompts, 'prompt');
            self::assertStringNotContainsString($leak, $html, 'rendered template');
            self::assertStringNotContainsString($leak, $stored, 'stored artifact');
        }
    }

    private function generationCompletion(string $kind): FakeTextCompletion
    {
        $completion = new FakeTextCompletion();
        match ($kind) {
            'faq'   => $completion->structuredResult = ['faq' => [['question' => 'How much did revenue grow?', 'answer' => 'By twelve percent.']]],
            'story' => $completion->jsonResult       = ['slides' => [
                ['role' => 'cover', 'headline' => 'Quarterly Results', 'subline' => 'Q3'],
                ['role' => 'outro', 'headline' => 'Growth continues', 'subline' => 'Read more'],
            ]],
            'slide_deck' => $completion->structuredResult = [
                'title'    => 'Quarterly report', 'subtitle' => 'Q3 at a glance',
                'slides'   => [['heading' => 'Revenue', 'bullets' => ['Up by twelve percent']]],
                'takeaway' => 'Growth continues.',
            ],
            'handout' => $completion->structuredResult = [
                'title'    => 'Quarterly report', 'lead' => 'Revenue grew by twelve percent.',
                'sections' => [['heading' => 'Revenue', 'paragraphs' => ['Driven by exports.']]],
                'keyFacts' => ['Revenue +12 %'],
            ],
            default => throw new LogicException('unknown generator ' . $kind),
        };

        return $completion;
    }

    private function generator(string $kind, FakeTextCompletion $completion, HtmlToImageRendererInterface $renderer, HtmlToPdfRendererInterface $printer): ArtifactGeneratorInterface
    {
        $jobs    = $this->get(JobProcessingRepository::class);
        $budget  = new FakeBudgetService();
        $logger  = new NullLogger();
        $storage = $this->get(JobFileStorage::class);
        $views   = $this->get(ViewFactoryInterface::class);
        $labels  = new TextLabels($this->get(LanguageServiceFactory::class));

        return match ($kind) {
            'faq'        => new FaqGenerator($jobs, $budget, $logger, $completion, $labels),
            'story'      => new StoryGenerator($jobs, $budget, $logger, $completion, $renderer, new GdImageCompositor(new NullLogger()), $this->unavailableImages(), $storage, $views, $this->noSlideshow()),
            'slide_deck' => new SlideDeckGenerator($jobs, $budget, $logger, $completion, $printer, $storage, $views),
            'handout'    => new HandoutGenerator($jobs, $budget, $logger, $completion, $printer, $storage, $views, $labels),
            default      => throw new LogicException('unknown generator ' . $kind),
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

    /** Keeps the HTML and hands back a copy of the PDF Chromium printed. */
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

    /** Keeps the HTML and hands back a copy of a PNG Chromium rendered. */
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

    private function noSlideshow(): SlideshowRendererInterface
    {
        return new class implements SlideshowRendererInterface {
            public function render(array $imagePaths, int $width, int $height, float $secondsPerImage, array $metadata): string
            {
                throw new LogicException('The job asks for no video');
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
                // Never reached: isAvailable() is false.
            }
        };
    }
}
