<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Testing\FakeBudgetService;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\ValueObject\AiLabelSettings;
use Netresearch\NrRepurpose\Domain\ValueObject\CapabilityGrants;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\ResolvedPromptSnippets;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\Image\ImageGeneratorInterface;
use Netresearch\NrRepurpose\Generator\SchaubildGenerator;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Pipeline\JobProgress;
use Netresearch\NrRepurpose\Provenance\AiProvenance;
use Netresearch\NrRepurpose\Provenance\DigitalSourceType;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\ImageCompositorInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Service\CallerSource;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\PromptBoundaryAssertions;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StatusRecordingJobRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceStorage;

final class SchaubildGeneratorTest extends TestCase
{
    use PromptBoundaryAssertions;

    private function context(ResolvedPromptSnippets $snippets = new ResolvedPromptSnippets(), ?CapabilityGrants $grants = null, string $summary = 'Summary', AiLabelSettings $aiLabel = new AiLabelSettings()): GenerationContext
    {
        $document = new SourceDocument('Report', 'text', 'https://example.com/', 0, 'en');
        $brief    = new ContentBrief('Report', $summary, ['A', 'B'], [['heading' => 'H', 'body' => 'B']], 'All', 'en');

        return new GenerationContext(['uid' => 11, 'theme' => 'nr', 'be_user' => 4, 'want_schaubild' => 1], $document, $brief, 'nr', 4, $snippets, grants: $grants ?? CapabilityGrants::all(), aiLabel: $aiLabel);
    }

    /**
     * Real prompt building and code-fence stripping stay active; only the Fluid theme-template
     * seam (renderTemplate needs a booted TYPO3 view factory) is stubbed out.
     */
    private function generator(
        HtmlToImageRendererInterface $renderer,
        ImageCompositorInterface $compositor,
        ImageGeneratorInterface $imageGenerator,
        JobFileStorage $storage,
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
        ?CompletionServiceInterface $completion = null,
    ): SchaubildGenerator {
        $completion ??= $this->completion();

        return new class ($jobs, $budget, $completion, $renderer, $compositor, $imageGenerator, $storage) extends SchaubildGenerator {
            public function __construct(
                JobProcessingRepository $jobs,
                BudgetServiceInterface $budget,
                CompletionServiceInterface $completion,
                HtmlToImageRendererInterface $renderer,
                ImageCompositorInterface $compositor,
                ImageGeneratorInterface $imageGenerator,
                JobFileStorage $storage,
            ) {
                parent::__construct($jobs, $budget, new NullLogger(), $completion, $renderer, $compositor, $imageGenerator, $storage);
            }

            /** @var list<array<string, mixed>> the variables of every theme-template render */
            public array $renderedVariables = [];

            protected function renderTemplate(string $area, string $theme, array $variables): string
            {
                $this->renderedVariables[] = $variables;

                return sprintf(
                    '<html data-transparent="%d"><body>%s</body></html>',
                    ($variables['transparent'] ?? false) ? 1 : 0,
                    (string) ($variables['bodyHtml'] ?? ''),
                );
            }
        };
    }

    public function testStripCodeFencesRemovesMarkdownFenceFromLlmHtml(): void
    {
        // Bypass the parent constructor: stripCodeFences is a pure static helper.
        $subject = new class extends SchaubildGenerator {
            public function __construct() {}

            public function expose(string $html): string
            {
                return self::stripCodeFences($html);
            }
        };

        self::assertSame('<p>x</p>', $subject->expose("```html\n<p>x</p>\n```"));
        self::assertSame('<p>x</p>', $subject->expose("```\n<p>x</p>\n```"));
        // Single-line fence (no newline) must keep the HTML, not wipe it.
        self::assertSame('<p>x</p>', $subject->expose('```html<p>x</p>```'));
        // Unfenced input is returned trimmed but otherwise untouched.
        self::assertSame('<p>x</p>', $subject->expose('  <p>x</p>  '));
    }

    public function testProducesThreeVariantArtifactsWhenBudgetAllows(): void
    {
        $compositor     = $this->compositor();
        $imageGenerator = $this->imageGenerator();
        $jobs           = $this->jobs();

        $generator = $this->generator($this->renderer(), $compositor, $imageGenerator, $this->storage(), $jobs, $this->allowingBudget());

        self::assertTrue($generator->generate($this->context()));
        self::assertSame(
            [['schaubild', 'html'], ['schaubild', 'html_bg'], ['schaubild', 'ki_image']],
            $jobs->inserted,
        );
        foreach ($jobs->updates as $update) {
            self::assertSame('done', $update['status']);
            self::assertArrayHasKey('source_html', $update);
            self::assertGreaterThan(0, (int) $update['file_uid']);
        }

        self::assertSame(1, $compositor->overlayCalls);     // html_bg composited
        self::assertSame(2, $imageGenerator->calls);        // bg (html_bg) + full (ki_image)
    }

    public function testOverBudgetFailsImageVariantsButHtmlSucceeds(): void
    {
        $jobs           = $this->jobs();
        $imageGenerator = $this->imageGenerator();

        $generator = $this->generator($this->renderer(), $this->compositor(), $imageGenerator, $this->storage(), $jobs, $this->denyingBudget());

        self::assertTrue($generator->generate($this->context()));
        self::assertSame('done', $jobs->updates[$jobs->uidForVariant('html')]['status']);
        self::assertSame('failed', $jobs->updates[$jobs->uidForVariant('html_bg')]['status']);
        self::assertSame('failed', $jobs->updates[$jobs->uidForVariant('ki_image')]['status']);
        self::assertSame(0, $imageGenerator->calls);
    }

    public function testWithoutTheVisionGrantBothImageVariantsFailButHtmlSucceeds(): void
    {
        $jobs           = $this->jobs();
        $imageGenerator = $this->imageGenerator();
        $completion     = $this->completion();

        $generator = $this->generator($this->renderer(), $this->compositor(), $imageGenerator, $this->storage(), $jobs, $this->allowingBudget(), $completion);

        self::assertTrue($generator->generate($this->context(new ResolvedPromptSnippets(), new CapabilityGrants(audio: true, vision: false))));
        // Only the opaque diagram is rendered; the transparent one feeds html_bg alone.
        self::assertCount(1, $completion->completeMarkdownCalls);
        self::assertSame('done', $jobs->updates[$jobs->uidForVariant('html')]['status']);
        foreach (['html_bg', 'ki_image'] as $variant) {
            $update = $jobs->updates[$jobs->uidForVariant($variant)];
            self::assertSame('failed', $update['status']);
            self::assertStringContainsString('nrrepurpose:generate_vision', (string) $update['error_message']);
        }

        self::assertSame(0, $imageGenerator->calls);
    }

    public function testImagePromptPreambleIsPrependedToBothAiImagePrompts(): void
    {
        $imageGenerator                 = $this->imageGenerator();
        $imageGenerator->promptPreamble = 'Always use the corporate teal palette.';

        $generator = $this->generator($this->renderer(), $this->compositor(), $imageGenerator, $this->storage(), $this->jobs(), $this->allowingBudget(), $this->completion());

        self::assertTrue($generator->generate($this->context(new ResolvedPromptSnippets())));

        // The editor-maintained preamble leads both image prompts (background + ki_image),
        // so the exact sent text — preamble included — is what lands in the metadata.
        self::assertCount(2, $imageGenerator->prompts);
        foreach ($imageGenerator->prompts as $prompt) {
            self::assertStringStartsWith("Always use the corporate teal palette.\n\n", $prompt);
        }
    }

    public function testSnippetSectionsAndHintsFlowIntoTheLlmAndImagePrompts(): void
    {
        $completion     = $this->completion();
        $imageGenerator = $this->imageGenerator();
        $generator      = $this->generator($this->renderer(), $this->compositor(), $imageGenerator, $this->storage(), $this->jobs(), $this->allowingBudget(), $completion);

        $snippets = new ResolvedPromptSnippets(
            schaubildSections: "TARGET AUDIENCE:\nInvestors\n\nSTYLE:\nHand-drawn sketch look",
            audienceHint: 'Investors',
            styleHint: 'Hand-drawn sketch look',
        );
        self::assertTrue($generator->generate($this->context($snippets)));

        // Composed sections are editor configuration: they go into the system prompt of the
        // diagram-body call (both render passes), never into the source-material block.
        foreach ($completion->completeMarkdownCalls as $call) {
            $systemPrompt = (string) $call['options']?->getSystemPrompt();
            self::assertStringContainsString("TARGET AUDIENCE:\nInvestors", $systemPrompt);
            self::assertStringContainsString("STYLE:\nHand-drawn sketch look", $systemPrompt);
            self::assertStringNotContainsString('TARGET AUDIENCE', $call['prompt']);
        }

        // Style/audience hints are woven into both image prompts (background + ki_image).
        self::assertCount(2, $imageGenerator->prompts);
        foreach ($imageGenerator->prompts as $prompt) {
            self::assertStringContainsString('Visual style: Hand-drawn sketch look', $prompt);
            self::assertStringContainsString('Target audience: Investors', $prompt);
        }
    }

    /**
     * Every diagram-body call names this extension and its pipeline step, so nr-llm
     * Analytics can attribute the cost instead of listing it as "Unattributed".
     */
    public function testEveryDiagramBodyCallNamesThisExtensionAndTheDiagramOperation(): void
    {
        $completion = $this->completion();
        $generator  = $this->generator($this->renderer(), $this->compositor(), $this->imageGenerator(), $this->storage(), $this->jobs(), $this->allowingBudget(), $completion);

        self::assertTrue($generator->generate($this->context()));

        self::assertNotSame([], $completion->completeMarkdownCalls);
        foreach ($completion->completeMarkdownCalls as $call) {
            $options = $call['options'];
            self::assertInstanceOf(ChatOptions::class, $options);
            self::assertSame('nr_repurpose', $options->getCallerSourceExtension());
            self::assertSame(CallerSource::GENERATE_DIAGRAM, $options->getCallerSourceOperation());
            // The wither returns a copy — the budget guard must survive it.
            self::assertSame(4, $options->getBeUserUid());
        }
    }

    public function testWithoutSnippetsPromptsCarryNoSectionOrHintBlocks(): void
    {
        $completion     = $this->completion();
        $imageGenerator = $this->imageGenerator();
        $generator      = $this->generator($this->renderer(), $this->compositor(), $imageGenerator, $this->storage(), $this->jobs(), $this->allowingBudget(), $completion);

        self::assertTrue($generator->generate($this->context()));

        foreach ($completion->completeMarkdownCalls as $call) {
            self::assertStringNotContainsString('TARGET AUDIENCE', $call['prompt']);
            self::assertStringNotContainsString('TARGET AUDIENCE', (string) $call['options']?->getSystemPrompt());
        }

        foreach ($imageGenerator->prompts as $prompt) {
            self::assertStringNotContainsString('Visual style:', $prompt);
            self::assertStringNotContainsString('Target audience:', $prompt);
        }
    }

    public function testRecordsFullPromptsAndActualModelInVariantMetadata(): void
    {
        $completion     = $this->completion();
        $imageGenerator = $this->imageGenerator();
        $jobs           = $this->jobs();
        $generator      = $this->generator($this->renderer(), $this->compositor(), $imageGenerator, $this->storage(), $jobs, $this->allowingBudget(), $completion);

        self::assertTrue($generator->generate($this->context()));

        // html: the diagram-body LLM call, verbatim and complete.
        $html = json_decode((string) $jobs->updates[$jobs->uidForVariant('html')]['metadata'], true);
        self::assertSame($completion->completeMarkdownCalls[0]['options']?->getSystemPrompt(), $html['prompts']['system']);
        self::assertStringStartsWith(
            'You are an information designer. Output a raw HTML fragment only — no Markdown, no code fences.',
            $html['prompts']['system'],
        );
        self::assertSame($completion->completeMarkdownCalls[0]['prompt'], $html['prompts']['user']);
        self::assertArrayNotHasKey('image', $html['prompts']);

        // html_bg: LLM prompts AND the background image prompt + the model that actually ran.
        $htmlBg = json_decode((string) $jobs->updates[$jobs->uidForVariant('html_bg')]['metadata'], true);
        self::assertSame('stub-image-model', $htmlBg['bgModel']);
        self::assertSame($completion->completeMarkdownCalls[0]['prompt'], $htmlBg['prompts']['user']);
        self::assertSame($imageGenerator->prompts[0], $htmlBg['prompts']['image']);
        self::assertSame('stub-image-model', $htmlBg['prompts']['imageModel']);
        self::assertSame('1536x1024', $htmlBg['prompts']['imageSize']);

        // ki_image: image-call parameters only (its image prompt derives from the brief, not the HTML).
        $ki = json_decode((string) $jobs->updates[$jobs->uidForVariant('ki_image')]['metadata'], true);
        self::assertSame('stub-image-model', $ki['model']);
        self::assertSame($imageGenerator->prompts[1], $ki['prompts']['image']);
        self::assertSame('stub-image-model', $ki['prompts']['imageModel']);
        self::assertSame('1536x1024', $ki['prompts']['imageSize']);
        self::assertArrayNotHasKey('system', $ki['prompts']);
        self::assertArrayNotHasKey('user', $ki['prompts']);
    }

    public function testEveryVariantIsStoredAiLabelledWithItsDigitalSourceType(): void
    {
        $storage = $this->storage();
        $jobs    = $this->jobs();

        $generator = $this->generator($this->renderer(), $this->compositor(), $this->imageGenerator(), $storage, $jobs, $this->allowingBudget());
        self::assertTrue($generator->generate($this->context(aiLabel: new AiLabelSettings('nr_repurpose 9.9.9'))));

        $expected = [
            'html'     => ['schaubild-html.png', new AiProvenance('nr_repurpose 9.9.9', DigitalSourceType::CompositeWithTrainedAlgorithmicMedia)],
            'html_bg'  => ['schaubild-html-bg.png', new AiProvenance('nr_repurpose 9.9.9', DigitalSourceType::CompositeWithTrainedAlgorithmicMedia, ['image' => 'stub-image-model'])],
            'ki_image' => ['schaubild-ki.png', new AiProvenance('nr_repurpose 9.9.9', DigitalSourceType::TrainedAlgorithmicMedia, ['image' => 'stub-image-model'])],
        ];
        foreach ($expected as $variant => [$fileName, $provenance]) {
            self::assertEquals($provenance, $storage->provenanceByName[$fileName] ?? null, $variant);
            $metadata = json_decode((string) $jobs->updates[$jobs->uidForVariant($variant)]['metadata'], true);
            self::assertSame($provenance->toArray(), $metadata['aiLabel'], $variant);
        }
    }

    public function testTheVisibleLabelSettingReachesEveryDiagramRender(): void
    {
        foreach (['KI-generiert', null] as $label) {
            $generator = $this->generator($this->renderer(), $this->compositor(), $this->imageGenerator(), $this->storage(), $this->jobs(), $this->allowingBudget());
            $generator->generate($this->context(aiLabel: new AiLabelSettings(imageLabel: $label)));

            // Opaque (html) and transparent (html_bg overlay) render.
            self::assertCount(2, $generator->renderedVariables);
            self::assertSame([$label, $label], array_column($generator->renderedVariables, 'aiLabel'));
        }
    }

    public function testLayoutImageSizeHintDrivesBothAiImageCalls(): void
    {
        $imageGenerator = $this->imageGenerator();
        $jobs           = $this->jobs();
        $generator      = $this->generator($this->renderer(), $this->compositor(), $imageGenerator, $this->storage(), $jobs, $this->allowingBudget());

        $snippets = new ResolvedPromptSnippets(schaubildImageSize: '1920x1088');
        self::assertTrue($generator->generate($this->context($snippets)));

        self::assertSame(['1920x1088', '1920x1088'], $imageGenerator->sizes);   // bg + ki_image
        $htmlBg = json_decode((string) $jobs->updates[$jobs->uidForVariant('html_bg')]['metadata'], true);
        self::assertSame('1920x1088', $htmlBg['prompts']['imageSize']);   // effective size recorded
        $ki = json_decode((string) $jobs->updates[$jobs->uidForVariant('ki_image')]['metadata'], true);
        self::assertSame('1920x1088', $ki['prompts']['imageSize']);
    }

    public function testInvalidImageSizeHintsFallBackToTheDefaultSize(): void
    {
        // Bad syntax, not divisible by 16, and out-of-bounds digit counts must never
        // fail the artifact — they fall back to the generator default.
        foreach (['nonsense', '1000x1080', '8x1080', '19200x1080'] as $hint) {
            $imageGenerator = $this->imageGenerator();
            $generator      = $this->generator($this->renderer(), $this->compositor(), $imageGenerator, $this->storage(), $this->jobs(), $this->allowingBudget());

            $snippets = new ResolvedPromptSnippets(schaubildImageSize: $hint);
            self::assertTrue($generator->generate($this->context($snippets)));
            self::assertSame(['1536x1024', '1536x1024'], $imageGenerator->sizes, 'hint: ' . $hint);
        }
    }

    public function testReportsHtmlAndVariantProgressSteps(): void
    {
        $progressJobs = new StatusRecordingJobRepository();
        $generator    = $this->generator($this->renderer(), $this->compositor(), $this->imageGenerator(), $this->storage(), $this->jobs(), $this->allowingBudget());
        $ctx          = $this->context()->withProgress(new JobProgress($progressJobs, 11, 30.0, 100.0));

        self::assertTrue($generator->generate($ctx));
        self::assertSame([
            'Schaubild: building HTML',
            'Schaubild: variant html (1/3)',
            'Schaubild: variant html_bg (2/3)',
            'Schaubild: generating background image',
            'Schaubild: variant ki_image (3/3)',
        ], $progressJobs->steps());

        $progresses = $progressJobs->progresses();
        $sorted     = $progresses;
        sort($sorted);
        self::assertSame($sorted, $progresses);
    }

    public function testSupportsReadsWantSchaubildFlag(): void
    {
        $generator = $this->generator($this->renderer(), $this->compositor(), $this->imageGenerator(), $this->storage(), $this->jobs(), $this->allowingBudget());
        self::assertTrue($generator->supports($this->context()));
    }

    private function completion(): FakeCompletionService
    {
        $completion                 = new FakeCompletionService();
        $completion->markdownResult = '<p>body</p>';

        return $completion;
    }

    private function renderer(): HtmlToImageRendererInterface
    {
        return new class implements HtmlToImageRendererInterface {
            public function render(string $html, int $width, ?int $height, float $deviceScaleFactor = 1.0, bool $transparent = false): string
            {
                $path = sys_get_temp_dir() . '/render_' . bin2hex(random_bytes(4)) . '.png';
                file_put_contents($path, 'PNG');

                return $path;
            }
        };
    }

    private function compositor(): ImageCompositorInterface
    {
        return new class implements ImageCompositorInterface {
            public int $overlayCalls = 0;

            public function overlay(string $backgroundPng, string $foregroundPng, string $outPath): string
            {
                ++$this->overlayCalls;
                file_put_contents($outPath, 'COMPOSITED');

                return $outPath;
            }
        };
    }

    private function imageGenerator(): ImageGeneratorInterface
    {
        return new class implements ImageGeneratorInterface {
            public int $calls = 0;

            public bool $available = true;

            /** @var list<string> */
            public array $prompts = [];

            /** @var list<string> */
            public array $sizes = [];

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function getModel(): string
            {
                return 'stub-image-model';
            }

            public string $promptPreamble = '';

            public function getPromptPreamble(): string
            {
                return $this->promptPreamble;
            }

            public function generateToFile(string $prompt, string $size, string $outputPath): void
            {
                ++$this->calls;
                $this->prompts[] = $prompt;
                $this->sizes[]   = $size;
                file_put_contents($outputPath, 'PNG');
            }
        };
    }

    private function storage(): JobFileStorage
    {
        return new class ($this->createStub(ResourceStorage::class)) extends JobFileStorage {
            private int $uid = 0;

            public function __construct(private readonly ResourceStorage $falStorage) {}

            /** @var array<string, ?AiProvenance> file name => provenance passed to store() */
            public array $provenanceByName = [];

            public function store(string $content, string $fileName, ?AiProvenance $provenance = null): File
            {
                $this->provenanceByName[$fileName] = $provenance;
                ++$this->uid;

                return new File(['uid' => $this->uid], $this->falStorage);
            }
        };
    }

    private function jobs(): JobProcessingRepository
    {
        return new class extends JobProcessingRepository {
            private int $nextUid = 200;

            /** @var list<array{0: string, 1: string}> */
            public array $inserted = [];

            /** @var array<int, array<string, mixed>> */
            public array $updates = [];

            /** @var array<string, int> */
            private array $variantUid = [];

            public function __construct() {}

            public function insertArtifact(int $jobUid, ArtifactType $type, string $variant, int $fileUid, ArtifactStatus $status, ?string $error = null): int
            {
                $this->inserted[]           = [$type->value, $variant];
                $uid                        = $this->nextUid++;
                $this->variantUid[$variant] = $uid;

                return $uid;
            }

            public function updateArtifact(int $artifactUid, array $fields): void
            {
                $this->updates[$artifactUid] = $fields;
            }

            public function uidForVariant(string $variant): int
            {
                return $this->variantUid[$variant];
            }
        };
    }

    private function allowingBudget(): FakeBudgetService
    {
        return new FakeBudgetService();
    }

    private function denyingBudget(): FakeBudgetService
    {
        $budget              = new FakeBudgetService();
        $budget->checkResult = BudgetCheckResult::denied('LIMIT_DAILY', 9.0, 9.0, 'no');

        return $budget;
    }

    public function testAnInstructionPayloadStaysInsideTheSourceBlock(): void
    {
        $completion = $this->completion();
        $this->generator($this->renderer(), $this->compositor(), $this->imageGenerator(), $this->storage(), $this->jobs(), $this->allowingBudget(), $completion)
            ->generate($this->context(summary: self::INSTRUCTION_PAYLOAD));

        self::assertNotSame([], $completion->completeMarkdownCalls);
        foreach ($completion->completeMarkdownCalls as $call) {
            self::assertInstructionPayloadContained((string) $call['options']?->getSystemPrompt(), $call['prompt']);
        }
    }

    public function testASpoofedSourceTagIsNeutralised(): void
    {
        $completion = $this->completion();
        $this->generator($this->renderer(), $this->compositor(), $this->imageGenerator(), $this->storage(), $this->jobs(), $this->allowingBudget(), $completion)
            ->generate($this->context(summary: self::SPOOF_PAYLOAD));

        foreach ($completion->completeMarkdownCalls as $call) {
            self::assertSpoofNeutralised((string) $call['options']?->getSystemPrompt(), $call['prompt']);
        }
    }
}
