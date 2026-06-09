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
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\Image\ImageGeneratorInterface;
use Netresearch\NrRepurpose\Generator\SchaubildGenerator;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\ImageCompositorInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\File;

final class SchaubildGeneratorTest extends TestCase
{
    private function context(): GenerationContext
    {
        $document = new SourceDocument('Report', 'text', 'https://example.com/', 0, 'en');
        $brief = new ContentBrief('Report', 'Summary', ['A', 'B'], [['heading' => 'H', 'body' => 'B']], 'All', 'en');

        return new GenerationContext(['uid' => 11, 'theme' => 'nr', 'be_user' => 4, 'want_schaubild' => 1], $document, $brief, 'nr', 4);
    }

    private function generator(
        HtmlToImageRendererInterface $renderer,
        ImageCompositorInterface $compositor,
        ImageGeneratorInterface $imageGenerator,
        JobFileStorage $storage,
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
    ): SchaubildGenerator {
        return new class($jobs, $budget, $this->completion(), $renderer, $compositor, $imageGenerator, $storage) extends SchaubildGenerator {
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

            protected function renderDiagramHtml(GenerationContext $ctx, bool $transparent): string
            {
                return $transparent
                    ? '<html><body style="background:transparent">DIAGRAM</body></html>'
                    : '<html><body>DIAGRAM</body></html>';
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

    public function testSupportsReadsWantSchaubildFlag(): void
    {
        $generator = $this->generator($this->renderer(), $this->compositor(), $this->imageGenerator(), $this->storage(), $this->jobs(), $this->allowingBudget());
        self::assertTrue($generator->supports($this->context()));
    }

    private function completion(): CompletionServiceInterface
    {
        return new class implements CompletionServiceInterface {
            public function complete(string $p, ?ChatOptions $o = null): CompletionResponse { throw new \LogicException('x'); }
            public function completeJson(string $p, ?ChatOptions $o = null): array { throw new \LogicException('x'); }
            public function completeMarkdown(string $p, ?ChatOptions $o = null): string { return '<p>body</p>'; }
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

            public function isAvailable(): bool { return $this->available; }

            public function generateToFile(string $prompt, string $size, string $outputPath): void
            {
                $this->calls++;
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
