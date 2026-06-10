<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\ResolvedPromptSnippets;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\Image\ImageGeneratorInterface;
use Netresearch\NrRepurpose\Generator\SchaubildGenerator;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Pipeline\JobProgress;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\ImageCompositorInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StatusRecordingJobRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\File;

final class SchaubildGeneratorTest extends TestCase
{
    private function context(ResolvedPromptSnippets $snippets = new ResolvedPromptSnippets()): GenerationContext
    {
        $document = new SourceDocument('Report', 'text', 'https://example.com/', 0, 'en');
        $brief = new ContentBrief('Report', 'Summary', ['A', 'B'], [['heading' => 'H', 'body' => 'B']], 'All', 'en');

        return new GenerationContext(['uid' => 11, 'theme' => 'nr', 'be_user' => 4, 'want_schaubild' => 1], $document, $brief, 'nr', 4, $snippets);
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

        return new class($jobs, $budget, $completion, $renderer, $compositor, $imageGenerator, $storage) extends SchaubildGenerator {
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

            protected function renderTemplate(string $area, string $theme, array $variables): string
            {
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
        $compositor = $this->compositor();
        $imageGenerator = $this->imageGenerator();
        $jobs = $this->jobs();

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
        $jobs = $this->jobs();
        $imageGenerator = $this->imageGenerator();

        $generator = $this->generator($this->renderer(), $this->compositor(), $imageGenerator, $this->storage(), $jobs, $this->denyingBudget());

        self::assertTrue($generator->generate($this->context()));
        self::assertSame('done', $jobs->updates[$jobs->uidForVariant('html')]['status']);
        self::assertSame('failed', $jobs->updates[$jobs->uidForVariant('html_bg')]['status']);
        self::assertSame('failed', $jobs->updates[$jobs->uidForVariant('ki_image')]['status']);
        self::assertSame(0, $imageGenerator->calls);
    }

    public function testSnippetSectionsAndHintsFlowIntoTheLlmAndImagePrompts(): void
    {
        $completion = $this->completion();
        $imageGenerator = $this->imageGenerator();
        $generator = $this->generator($this->renderer(), $this->compositor(), $imageGenerator, $this->storage(), $this->jobs(), $this->allowingBudget(), $completion);

        $snippets = new ResolvedPromptSnippets(
            schaubildSections: "TARGET AUDIENCE:\nInvestors\n\nSTYLE:\nHand-drawn sketch look",
            audienceHint: 'Investors',
            styleHint: 'Hand-drawn sketch look',
        );
        self::assertTrue($generator->generate($this->context($snippets)));

        // Composed sections are appended to the diagram-body LLM prompt (both render passes).
        foreach ($completion->prompts as $prompt) {
            self::assertStringContainsString("TARGET AUDIENCE:\nInvestors", $prompt);
            self::assertStringContainsString("STYLE:\nHand-drawn sketch look", $prompt);
        }
        // Style/audience hints are woven into both image prompts (background + ki_image).
        self::assertCount(2, $imageGenerator->prompts);
        foreach ($imageGenerator->prompts as $prompt) {
            self::assertStringContainsString('Visual style: Hand-drawn sketch look', $prompt);
            self::assertStringContainsString('Target audience: Investors', $prompt);
        }
    }

    public function testWithoutSnippetsPromptsCarryNoSectionOrHintBlocks(): void
    {
        $completion = $this->completion();
        $imageGenerator = $this->imageGenerator();
        $generator = $this->generator($this->renderer(), $this->compositor(), $imageGenerator, $this->storage(), $this->jobs(), $this->allowingBudget(), $completion);

        self::assertTrue($generator->generate($this->context()));

        foreach ($completion->prompts as $prompt) {
            self::assertStringNotContainsString('TARGET AUDIENCE', $prompt);
        }
        foreach ($imageGenerator->prompts as $prompt) {
            self::assertStringNotContainsString('Visual style:', $prompt);
            self::assertStringNotContainsString('Target audience:', $prompt);
        }
    }

    public function testRecordsFullPromptsAndActualModelInVariantMetadata(): void
    {
        $completion = $this->completion();
        $imageGenerator = $this->imageGenerator();
        $jobs = $this->jobs();
        $generator = $this->generator($this->renderer(), $this->compositor(), $imageGenerator, $this->storage(), $jobs, $this->allowingBudget(), $completion);

        self::assertTrue($generator->generate($this->context()));

        // html: the diagram-body LLM call, verbatim and complete.
        $html = json_decode((string) $jobs->updates[$jobs->uidForVariant('html')]['metadata'], true);
        self::assertSame(
            'You are an information designer. Output a raw HTML fragment only — no Markdown, no code fences.',
            $html['prompts']['system'],
        );
        self::assertSame($completion->prompts[0], $html['prompts']['user']);
        self::assertArrayNotHasKey('image', $html['prompts']);

        // html_bg: LLM prompts AND the background image prompt + the model that actually ran.
        $htmlBg = json_decode((string) $jobs->updates[$jobs->uidForVariant('html_bg')]['metadata'], true);
        self::assertSame('stub-image-model', $htmlBg['bgModel']);
        self::assertSame($completion->prompts[0], $htmlBg['prompts']['user']);
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

    public function testReportsHtmlAndVariantProgressSteps(): void
    {
        $progressJobs = new StatusRecordingJobRepository();
        $generator = $this->generator($this->renderer(), $this->compositor(), $this->imageGenerator(), $this->storage(), $this->jobs(), $this->allowingBudget());
        $ctx = $this->context()->withProgress(new JobProgress($progressJobs, 11, 30.0, 100.0));

        self::assertTrue($generator->generate($ctx));
        self::assertSame([
            'Schaubild: building HTML',
            'Schaubild: variant html (1/3)',
            'Schaubild: variant html_bg (2/3)',
            'Schaubild: generating background image',
            'Schaubild: variant ki_image (3/3)',
        ], $progressJobs->steps());

        $progresses = $progressJobs->progresses();
        $sorted = $progresses;
        sort($sorted);
        self::assertSame($sorted, $progresses);
    }

    public function testSupportsReadsWantSchaubildFlag(): void
    {
        $generator = $this->generator($this->renderer(), $this->compositor(), $this->imageGenerator(), $this->storage(), $this->jobs(), $this->allowingBudget());
        self::assertTrue($generator->supports($this->context()));
    }

    private function completion(): CompletionServiceInterface
    {
        return new class implements CompletionServiceInterface {
            /** @var list<string> */
            public array $prompts = [];

            public function complete(string $p, ?ChatOptions $o = null): CompletionResponse { throw new \LogicException('x'); }
            public function completeJson(string $p, ?ChatOptions $o = null): array { throw new \LogicException('x'); }

            public function completeMarkdown(string $p, ?ChatOptions $o = null): string
            {
                $this->prompts[] = $p;

                return '<p>body</p>';
            }

            public function completeFactual(string $p, ?ChatOptions $o = null): CompletionResponse { throw new \LogicException('x'); }
            public function completeCreative(string $p, ?ChatOptions $o = null): CompletionResponse { throw new \LogicException('x'); }
        };
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
                $this->overlayCalls++;
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

            public function isAvailable(): bool { return $this->available; }

            public function getModel(): string { return 'stub-image-model'; }

            public function generateToFile(string $prompt, string $size, string $outputPath): void
            {
                $this->calls++;
                $this->prompts[] = $prompt;
                $this->sizes[] = $size;
                file_put_contents($outputPath, 'PNG');
            }
        };
    }

    private function storage(): JobFileStorage
    {
        return new class extends JobFileStorage {
            private int $uid = 0;

            public function __construct() {}

            public function store(string $content, string $fileName): File
            {
                $this->uid++;
                $file = (new \ReflectionClass(File::class))->newInstanceWithoutConstructor();
                (new \ReflectionProperty(File::class, 'properties'))->setValue($file, ['uid' => $this->uid]);

                return $file;
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
                $this->inserted[] = [$type->value, $variant];
                $uid = $this->nextUid++;
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

    private function allowingBudget(): BudgetServiceInterface
    {
        return new class implements BudgetServiceInterface {
            public function check(int $u, float $c = 0.0): BudgetCheckResult { return BudgetCheckResult::allowed(); }
        };
    }

    private function denyingBudget(): BudgetServiceInterface
    {
        return new class implements BudgetServiceInterface {
            public function check(int $u, float $c = 0.0): BudgetCheckResult { return BudgetCheckResult::denied('LIMIT_DAILY', 9.0, 9.0, 'no'); }
        };
    }
}
