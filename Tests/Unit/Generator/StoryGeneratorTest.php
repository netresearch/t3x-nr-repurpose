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
use Netresearch\NrRepurpose\Generator\StoryGenerator;
use Netresearch\NrRepurpose\Generator\Support\StorySlide;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\ImageCompositorInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\File;

final class StoryGeneratorTest extends TestCase
{
    private const THREE_SLIDES = ['slides' => [
        ['role' => 'cover', 'headline' => 'Big News', 'subline' => 'Details inside'],
        ['role' => 'point', 'headline' => 'Point one', 'subline' => 'It matters'],
        ['role' => 'outro', 'headline' => 'Takeaway', 'subline' => 'Source: example.com'],
    ]];

    /** @param list<string> $keyPoints */
    private function context(bool $wantStory = true, array $keyPoints = ['Point'], ResolvedPromptSnippets $snippets = new ResolvedPromptSnippets()): GenerationContext
    {
        $document = new SourceDocument('Report', 'text', 'https://example.com/', 0, 'en');
        $brief = new ContentBrief('Report', 'A crisp summary.', $keyPoints, [], 'All', 'en');

        return new GenerationContext(['uid' => 21, 'theme' => 'nr', 'be_user' => 5, 'want_story' => $wantStory ? 1 : 0], $document, $brief, 'nr', 5, $snippets);
    }

    /** @param array<mixed>|\Throwable $completionResult */
    private function generator(
        array|\Throwable $completionResult,
        HtmlToImageRendererInterface $renderer,
        ImageGeneratorInterface $imageGenerator,
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
        ImageCompositorInterface $compositor,
        ?CompletionServiceInterface $completion = null,
    ): StoryGenerator {
        $completion ??= $this->completion($completionResult);

        return new class($jobs, $budget, $completion, $renderer, $compositor, $imageGenerator) extends StoryGenerator {
            public function __construct(
                JobProcessingRepository $jobs,
                BudgetServiceInterface $budget,
                CompletionServiceInterface $completion,
                HtmlToImageRendererInterface $renderer,
                ImageCompositorInterface $compositor,
                ImageGeneratorInterface $imageGenerator,
            ) {
                parent::__construct(
                    $jobs,
                    $budget,
                    new NullLogger(),
                    $completion,
                    $renderer,
                    $compositor,
                    $imageGenerator,
                    new class extends JobFileStorage {
                        private int $uid = 0;
                        public function __construct() {}
                        public function store(string $content, string $fileName): File
                        {
                            $this->uid++;
                            $file = (new \ReflectionClass(File::class))->newInstanceWithoutConstructor();
                            (new \ReflectionProperty(File::class, 'properties'))->setValue($file, ['uid' => $this->uid]);

                            return $file;
                        }
                    },
                );
            }

            protected function renderSlideHtml(GenerationContext $ctx, StorySlide $slide, int $index, int $total, bool $transparent): string
            {
                return sprintf('<html><body>SLIDE %d/%d %s | %s | %s</body></html>', $index, $total, $slide->role, $slide->headline, $slide->subline);
            }
        };
    }

    public function testCreatesOneArtifactPerSlideWithRoleIndexAndTotalMetadata(): void
    {
        $renderer = $this->renderer();
        $jobs = $this->jobs();

        $generator = $this->generator(self::THREE_SLIDES, $renderer, $this->imageGenerator(false), $jobs, $this->allowingBudget(), $this->compositor());

        self::assertTrue($generator->generate($this->context()));
        self::assertSame(
            [['story', 'slide-1'], ['story', 'slide-2'], ['story', 'slide-3']],
            $jobs->inserted,
        );
        self::assertSame([1080, 1080, 1080], $renderer->widths);
        self::assertSame([1920, 1920, 1920], $renderer->heights);
        self::assertSame([false, false, false], $renderer->transparents);

        $roles = ['cover', 'point', 'outro'];
        foreach (['slide-1', 'slide-2', 'slide-3'] as $i => $variant) {
            $update = $jobs->updates[$jobs->uidForVariant($variant)];
            self::assertSame('done', $update['status']);
            self::assertGreaterThan(0, (int) $update['file_uid']);
            $metadata = json_decode((string) $update['metadata'], true);
            self::assertSame($roles[$i], $metadata['role']);
            self::assertSame($i + 1, $metadata['slideIndex']);
            self::assertSame(3, $metadata['slideTotal']);
            self::assertSame('flat', $metadata['background']);
        }
    }

    public function testGeneratesSharedKiBackgroundOnceAndCompositesEverySlide(): void
    {
        $renderer = $this->renderer();
        $compositor = $this->compositor();
        $imageGenerator = $this->imageGenerator(true);
        $jobs = $this->jobs();
        $budget = $this->allowingBudget();

        $generator = $this->generator(self::THREE_SLIDES, $renderer, $imageGenerator, $jobs, $budget, $compositor);

        self::assertTrue($generator->generate($this->context()));
        self::assertSame(1, $imageGenerator->calls);
        self::assertSame(3, $compositor->overlayCalls);
        self::assertSame([true, true, true], $renderer->transparents);
        self::assertSame([0.05], $budget->checkedCosts);
        foreach ($jobs->updates as $update) {
            $metadata = json_decode((string) $update['metadata'], true);
            self::assertSame('ki', $metadata['background']);
        }
    }

    public function testOverBudgetFallsBackToFlatSlides(): void
    {
        $renderer = $this->renderer();
        $imageGenerator = $this->imageGenerator(true);
        $jobs = $this->jobs();

        $generator = $this->generator(self::THREE_SLIDES, $renderer, $imageGenerator, $jobs, $this->denyingBudget(), $this->compositor());

        self::assertTrue($generator->generate($this->context()));
        self::assertSame(0, $imageGenerator->calls);
        self::assertSame([false, false, false], $renderer->transparents);
        foreach ($jobs->updates as $update) {
            self::assertSame('done', $update['status']);
        }
    }

    public function testBackgroundGenerationErrorFallsBackToFlatSlides(): void
    {
        $renderer = $this->renderer();
        $jobs = $this->jobs();
        $imageGenerator = new class implements ImageGeneratorInterface {
            public function isAvailable(): bool { return true; }
            public function generateToFile(string $prompt, string $size, string $outputPath): void
            {
                throw new \RuntimeException('image service exploded');
            }
        };

        $generator = $this->generator(self::THREE_SLIDES, $renderer, $imageGenerator, $jobs, $this->allowingBudget(), $this->compositor());

        self::assertTrue($generator->generate($this->context()));
        self::assertSame([false, false, false], $renderer->transparents);
        foreach ($jobs->updates as $update) {
            self::assertSame('done', $update['status']);
        }
    }

    public function testSkipsMalformedAndEmptySlideEntriesAndNormalizesUnknownRoles(): void
    {
        $jobs = $this->jobs();
        $response = ['slides' => [
            ['role' => 'cover', 'headline' => 'Valid one', 'subline' => 'Sub'],
            ['role' => 'point', 'headline' => '   ', 'subline' => 'Headline empty -> skipped'],
            'not-an-object',
            ['role' => 'epilogue', 'headline' => 'Valid two'],
        ]];

        $generator = $this->generator($response, $this->renderer(), $this->imageGenerator(false), $jobs, $this->allowingBudget(), $this->compositor());

        self::assertTrue($generator->generate($this->context()));
        self::assertSame([['story', 'slide-1'], ['story', 'slide-2']], $jobs->inserted);
        $metadata = json_decode((string) $jobs->updates[$jobs->uidForVariant('slide-2')]['metadata'], true);
        self::assertSame('point', $metadata['role']);   // unknown role degrades to "point"
        self::assertSame(2, $metadata['slideTotal']);
    }

    public function testCapsCarouselAtSixSlides(): void
    {
        $jobs = $this->jobs();
        $slides = [];
        for ($i = 1; $i <= 8; $i++) {
            $slides[] = ['role' => 'point', 'headline' => 'Slide ' . $i, 'subline' => ''];
        }

        $generator = $this->generator(['slides' => $slides], $this->renderer(), $this->imageGenerator(false), $jobs, $this->allowingBudget(), $this->compositor());

        self::assertTrue($generator->generate($this->context()));
        self::assertCount(6, $jobs->inserted);
        self::assertSame(['story', 'slide-6'], $jobs->inserted[5]);
        $metadata = json_decode((string) $jobs->updates[$jobs->uidForVariant('slide-6')]['metadata'], true);
        self::assertSame(6, $metadata['slideTotal']);
    }

    public function testZeroUsableSlidesFailsSingleStoryArtifact(): void
    {
        $jobs = $this->jobs();

        $generator = $this->generator(['slides' => [['headline' => '']]], $this->renderer(), $this->imageGenerator(false), $jobs, $this->allowingBudget(), $this->compositor());

        self::assertFalse($generator->generate($this->context()));
        self::assertSame([['story', 'default']], $jobs->inserted);
        $update = $jobs->updates[$jobs->uidForVariant('default')];
        self::assertSame('failed', $update['status']);
        self::assertStringContainsString('no usable slides', (string) $update['error_message']);
    }

    public function testCompletionFailureFailsSingleStoryArtifact(): void
    {
        $jobs = $this->jobs();

        $generator = $this->generator(new \RuntimeException('LLM down'), $this->renderer(), $this->imageGenerator(false), $jobs, $this->allowingBudget(), $this->compositor());

        self::assertFalse($generator->generate($this->context()));
        self::assertSame([['story', 'default']], $jobs->inserted);
        $update = $jobs->updates[$jobs->uidForVariant('default')];
        self::assertSame('failed', $update['status']);
        self::assertStringContainsString('LLM down', (string) $update['error_message']);
    }

    public function testFailedSlideRenderDoesNotAbortSiblingSlides(): void
    {
        $jobs = $this->jobs();
        $renderer = new class implements HtmlToImageRendererInterface {
            private int $call = 0;

            public function render(string $html, int $width, ?int $height, float $deviceScaleFactor = 1.0, bool $transparent = false): string
            {
                $this->call++;
                if ($this->call === 2) {
                    throw new \RuntimeException('chromium crashed');
                }
                $path = sys_get_temp_dir() . '/story_' . bin2hex(random_bytes(4)) . '.png';
                file_put_contents($path, 'PNG');

                return $path;
            }
        };

        $generator = $this->generator(self::THREE_SLIDES, $renderer, $this->imageGenerator(false), $jobs, $this->allowingBudget(), $this->compositor());

        self::assertTrue($generator->generate($this->context()));
        self::assertSame('done', $jobs->updates[$jobs->uidForVariant('slide-1')]['status']);
        self::assertSame('done', $jobs->updates[$jobs->uidForVariant('slide-3')]['status']);

        $failed = $jobs->updates[$jobs->uidForVariant('slide-2')];
        self::assertSame('failed', $failed['status']);
        self::assertStringContainsString('Story slide 2/3', (string) $failed['error_message']);
        // The failed row still carries its slide identity for the result view.
        $metadata = json_decode((string) $failed['metadata'], true);
        self::assertSame(2, $metadata['slideIndex']);
        self::assertSame(3, $metadata['slideTotal']);
    }

    public function testPromptAsksForTheCopyLimitsTheParserEnforces(): void
    {
        $completion = $this->completion(['slides' => [[
            'role' => 'cover',
            'headline' => str_repeat('H', 80),
            'subline' => str_repeat('s', 150),
        ]]]);
        $jobs = $this->jobs();
        $generator = $this->generator([], $this->renderer(), $this->imageGenerator(false), $jobs, $this->allowingBudget(), $this->compositor(), $completion);

        self::assertTrue($generator->generate($this->context()));
        // The prompt asks the LLM for the same limits the parser enforces below.
        self::assertStringContainsString('Headline <=60 chars and subline <=110 chars', $completion->lastPrompt);

        $html = (string) $jobs->updates[$jobs->uidForVariant('slide-1')]['source_html'];
        self::assertStringContainsString(str_repeat('H', 60), $html);
        self::assertStringNotContainsString(str_repeat('H', 61), $html);
        self::assertStringContainsString(str_repeat('s', 110), $html);
        self::assertStringNotContainsString(str_repeat('s', 111), $html);
    }

    public function testPlannedCostScalesWithExpectedSlideCount(): void
    {
        // 1 key point -> cover + 1 point + outro = 3 slides planned.
        $completion = $this->completion(self::THREE_SLIDES);
        $generator = $this->generator([], $this->renderer(), $this->imageGenerator(false), $this->jobs(), $this->allowingBudget(), $this->compositor(), $completion);
        $generator->generate($this->context(true, ['Point']));
        self::assertEqualsWithDelta(0.03, $completion->lastOptions?->getPlannedCost(), 1e-9);

        // 6 key points -> capped at 4 point slides -> 6 slides planned.
        $completion = $this->completion(self::THREE_SLIDES);
        $generator = $this->generator([], $this->renderer(), $this->imageGenerator(false), $this->jobs(), $this->allowingBudget(), $this->compositor(), $completion);
        $generator->generate($this->context(true, ['A', 'B', 'C', 'D', 'E', 'F']));
        self::assertEqualsWithDelta(0.06, $completion->lastOptions?->getPlannedCost(), 1e-9);
    }

    public function testSlidesPromptCarriesTheComposedSnippetSections(): void
    {
        $completion = $this->completion(self::THREE_SLIDES);
        $generator = $this->generator([], $this->renderer(), $this->imageGenerator(false), $this->jobs(), $this->allowingBudget(), $this->compositor(), $completion);
        $snippets = new ResolvedPromptSnippets(storySections: "TONE OF VOICE:\nUpbeat and concise\n\nLAYOUT:\nFull-bleed imagery");

        self::assertTrue($generator->generate($this->context(true, ['Point'], $snippets)));
        self::assertStringContainsString("TONE OF VOICE:\nUpbeat and concise", $completion->lastPrompt);
        self::assertStringContainsString("LAYOUT:\nFull-bleed imagery", $completion->lastPrompt);
    }

    public function testWithoutSnippetsSlidesPromptHasNoSectionBlocks(): void
    {
        $completion = $this->completion(self::THREE_SLIDES);
        $generator = $this->generator([], $this->renderer(), $this->imageGenerator(false), $this->jobs(), $this->allowingBudget(), $this->compositor(), $completion);

        self::assertTrue($generator->generate($this->context()));
        self::assertStringNotContainsString('TONE OF VOICE', $completion->lastPrompt);
        self::assertStringNotContainsString('LAYOUT:', $completion->lastPrompt);
    }

    public function testSupportsReadsWantStoryFlag(): void
    {
        $generator = $this->generator(self::THREE_SLIDES, $this->renderer(), $this->imageGenerator(false), $this->jobs(), $this->allowingBudget(), $this->compositor());
        self::assertTrue($generator->supports($this->context(true)));
        self::assertFalse($generator->supports($this->context(false)));
    }

    /** @param array<mixed>|\Throwable $result */
    private function completion(array|\Throwable $result): CompletionServiceInterface
    {
        return new class($result) implements CompletionServiceInterface {
            public ?ChatOptions $lastOptions = null;
            public string $lastPrompt = '';

            /** @param array<mixed>|\Throwable $result */
            public function __construct(private readonly array|\Throwable $result) {}

            public function complete(string $p, ?ChatOptions $o = null): CompletionResponse { throw new \LogicException('x'); }

            public function completeJson(string $p, ?ChatOptions $o = null): array
            {
                $this->lastPrompt = $p;
                $this->lastOptions = $o;
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }

            public function completeMarkdown(string $p, ?ChatOptions $o = null): string { throw new \LogicException('x'); }
            public function completeFactual(string $p, ?ChatOptions $o = null): CompletionResponse { throw new \LogicException('x'); }
            public function completeCreative(string $p, ?ChatOptions $o = null): CompletionResponse { throw new \LogicException('x'); }
        };
    }

    private function renderer(): HtmlToImageRendererInterface
    {
        return new class implements HtmlToImageRendererInterface {
            /** @var list<int> */
            public array $widths = [];
            /** @var list<int|null> */
            public array $heights = [];
            /** @var list<bool> */
            public array $transparents = [];

            public function render(string $html, int $width, ?int $height, float $deviceScaleFactor = 1.0, bool $transparent = false): string
            {
                $this->widths[] = $width;
                $this->heights[] = $height;
                $this->transparents[] = $transparent;
                $path = sys_get_temp_dir() . '/story_' . bin2hex(random_bytes(4)) . '.png';
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

    private function imageGenerator(bool $available): ImageGeneratorInterface
    {
        return new class($available) implements ImageGeneratorInterface {
            public int $calls = 0;

            public function __construct(private readonly bool $available) {}

            public function isAvailable(): bool { return $this->available; }

            public function generateToFile(string $prompt, string $size, string $outputPath): void
            {
                $this->calls++;
                file_put_contents($outputPath, 'PNG');
            }
        };
    }

    private function jobs(): JobProcessingRepository
    {
        return new class extends JobProcessingRepository {
            private int $nextUid = 300;
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
                $this->updates[$artifactUid] = array_merge($this->updates[$artifactUid] ?? [], $fields);
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
            /** @var list<float> */
            public array $checkedCosts = [];

            public function check(int $u, float $c = 0.0): BudgetCheckResult
            {
                $this->checkedCosts[] = $c;

                return BudgetCheckResult::allowed();
            }
        };
    }

    private function denyingBudget(): BudgetServiceInterface
    {
        return new class implements BudgetServiceInterface {
            public function check(int $u, float $c = 0.0): BudgetCheckResult { return BudgetCheckResult::denied('LIMIT_DAILY', 9.0, 9.0, 'no'); }
        };
    }
}
