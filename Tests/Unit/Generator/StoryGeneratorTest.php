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
use Netresearch\NrRepurpose\Generator\StoryGenerator;
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
    private function context(bool $wantStory = true): GenerationContext
    {
        $document = new SourceDocument('Report', 'text', 'https://example.com/', 0, 'en');
        $brief = new ContentBrief('Report', 'A crisp summary.', ['Point'], [], 'All', 'en');

        return new GenerationContext(['uid' => 21, 'theme' => 'nr', 'be_user' => 5, 'want_story' => $wantStory ? 1 : 0], $document, $brief, 'nr', 5);
    }

    private function generator(
        HtmlToImageRendererInterface $renderer,
        ImageGeneratorInterface $imageGenerator,
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
    ): StoryGenerator {
        return new class($jobs, $budget, $renderer, $imageGenerator) extends StoryGenerator {
            public function __construct(JobProcessingRepository $jobs, BudgetServiceInterface $budget, HtmlToImageRendererInterface $renderer, ImageGeneratorInterface $imageGenerator)
            {
                parent::__construct(
                    $jobs,
                    $budget,
                    new NullLogger(),
                    new class implements CompletionServiceInterface {
                        public function complete(string $p, ?ChatOptions $o = null): CompletionResponse { throw new \LogicException('x'); }
                        public function completeJson(string $p, ?ChatOptions $o = null): array { return ['headline' => 'Big News', 'subline' => 'Details inside']; }
                        public function completeMarkdown(string $p, ?ChatOptions $o = null): string { throw new \LogicException('x'); }
                        public function completeFactual(string $p, ?ChatOptions $o = null): CompletionResponse { throw new \LogicException('x'); }
                        public function completeCreative(string $p, ?ChatOptions $o = null): CompletionResponse { throw new \LogicException('x'); }
                    },
                    $renderer,
                    new class implements ImageCompositorInterface {
                        public function overlay(string $b, string $f, string $o): string { file_put_contents($o, 'C'); return $o; }
                    },
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

            protected function renderStoryHtml(GenerationContext $ctx, bool $transparent): string
            {
                return '<html><body>STORY</body></html>';
            }
        };
    }

    public function testRendersNineBySixteenOpaqueAndRecordsStoryArtifact(): void
    {
        $renderer = $this->renderer();
        $jobs = $this->jobs();

        $generator = $this->generator($renderer, $this->imageGenerator(false), $jobs, $this->allowingBudget());

        self::assertTrue($generator->generate($this->context()));
        self::assertSame([['story', 'default']], $jobs->inserted);
        self::assertSame(1080, $renderer->lastWidth);
        self::assertSame(1920, $renderer->lastHeight);
        self::assertFalse($renderer->lastTransparent);
        self::assertSame('done', $jobs->updates[300]['status']);
        self::assertGreaterThan(0, (int) $jobs->updates[300]['file_uid']);
    }

    public function testSupportsReadsWantStoryFlag(): void
    {
        $generator = $this->generator($this->renderer(), $this->imageGenerator(false), $this->jobs(), $this->allowingBudget());
        self::assertTrue($generator->supports($this->context(true)));
        self::assertFalse($generator->supports($this->context(false)));
    }

    private function renderer(): HtmlToImageRendererInterface
    {
        return new class implements HtmlToImageRendererInterface {
            public int $lastWidth = 0;
            public ?int $lastHeight = null;
            public bool $lastTransparent = true;

            public function render(string $html, int $width, ?int $height, float $deviceScaleFactor = 1.0, bool $transparent = false): string
            {
                $this->lastWidth = $width;
                $this->lastHeight = $height;
                $this->lastTransparent = $transparent;
                $path = sys_get_temp_dir() . '/story_' . bin2hex(random_bytes(4)) . '.png';
                file_put_contents($path, 'PNG');

                return $path;
            }
        };
    }

    private function imageGenerator(bool $available): ImageGeneratorInterface
    {
        return new class($available) implements ImageGeneratorInterface {
            public function __construct(private readonly bool $available) {}

            public function isAvailable(): bool { return $this->available; }

            public function generateToFile(string $prompt, string $size, string $outputPath): void
            {
                file_put_contents($outputPath, 'PNG');
            }
        };
    }

    private function jobs(): JobProcessingRepository
    {
        return new class extends JobProcessingRepository {
            /** @var list<array{0: string, 1: string}> */
            public array $inserted = [];
            /** @var array<int, array<string, mixed>> */
            public array $updates = [];

            public function __construct() {}

            public function insertArtifact(int $jobUid, ArtifactType $type, string $variant, int $fileUid, ArtifactStatus $status, ?string $error = null): int
            {
                $this->inserted[] = [$type->value, $variant];

                return 300;
            }

            public function updateArtifact(int $artifactUid, array $fields): void
            {
                $this->updates[$artifactUid] = $fields;
            }
        };
    }

    private function allowingBudget(): BudgetServiceInterface
    {
        return new class implements BudgetServiceInterface {
            public function check(int $u, float $c = 0.0): BudgetCheckResult { return BudgetCheckResult::allowed(); }
        };
    }
}
