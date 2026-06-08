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
use Netresearch\NrRepurpose\Generator\PodcastGenerator;
use Netresearch\NrRepurpose\Generator\Speech\SpeechSynthesizerInterface;
use Netresearch\NrRepurpose\Generator\Support\WebVttBuilder;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Rendering\AudioStitcherInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\File;

final class PodcastGeneratorTest extends TestCase
{
    private function context(int $wantPodcast = 1): GenerationContext
    {
        $document = new SourceDocument('Quarterly Report', 'Revenue grew. Costs fell.', 'https://example.com/report', 0, 'en');
        $brief = new ContentBrief(
            'Quarterly Report',
            'Revenue up, costs down.',
            ['Revenue +12%', 'Costs -5%'],
            [['heading' => 'Financials', 'body' => 'Details.']],
            'Investors',
            'en',
        );

        return new GenerationContext(
            ['uid' => 7, 'theme' => 'nr', 'be_user' => 3, 'want_podcast' => $wantPodcast],
            $document,
            $brief,
            'nr',
            3,
        );
    }

    private function completion(): CompletionServiceInterface
    {
        return new class implements CompletionServiceInterface {
            public ?ChatOptions $seenOptions = null;
            public string $seenPrompt = '';

            public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
            {
                throw new \LogicException('not used');
            }

            public function completeJson(string $prompt, ?ChatOptions $options = null): array
            {
                $this->seenPrompt = $prompt;
                $this->seenOptions = $options;

                return ['turns' => [
                    ['speaker' => 'Host A', 'text' => 'Welcome to the show.'],
                    ['speaker' => 'Host B', 'text' => 'Glad to be here.'],
                    ['speaker' => 'Host A', 'text' => 'Lets dig in.'],
                ]];
            }

            public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string
            {
                throw new \LogicException('not used');
            }

            public function completeFactual(string $prompt, ?ChatOptions $options = null): CompletionResponse
            {
                throw new \LogicException('not used');
            }

            public function completeCreative(string $prompt, ?ChatOptions $options = null): CompletionResponse
            {
                throw new \LogicException('not used');
            }
        };
    }

    /** @return SpeechSynthesizerInterface */
    private function speech(): SpeechSynthesizerInterface
    {
        return new class implements SpeechSynthesizerInterface {
            /** @var list<array{voice: string, path: string}> */
            public array $calls = [];
            public bool $available = true;

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function synthesizeToFile(string $text, string $voice, string $outputPath): void
            {
                file_put_contents($outputPath, 'AUDIO:' . $text);
                $this->calls[] = ['voice' => $voice, 'path' => $outputPath];
            }
        };
    }

    private function stitcher(): AudioStitcherInterface
    {
        return new class implements AudioStitcherInterface {
            /** @var list<string> */
            public array $concatInput = [];

            public function concat(array $mp3Paths, string $outPath): string
            {
                $this->concatInput = $mp3Paths;
                file_put_contents($outPath, 'STITCHED');

                return $outPath;
            }

            public function probeDurationSeconds(string $path): float
            {
                return str_contains($path, 'turn-0.') ? 3.0 : (str_contains($path, 'turn-1.') ? 2.0 : 1.0);
            }
        };
    }

    private function storage(): JobFileStorage
    {
        return new class extends JobFileStorage {
            /** @var array<string, string> */
            public array $contentByName = [];
            /** @var list<string> */
            public array $order = [];
            private int $uid = 0;

            public function __construct() {}

            public function store(string $content, string $fileName): File
            {
                $this->contentByName[$fileName] = $content;
                $this->order[] = $fileName;
                $this->uid++;
                $file = (new \ReflectionClass(File::class))->newInstanceWithoutConstructor();
                $ref = new \ReflectionProperty(File::class, 'properties');
                $ref->setValue($file, ['uid' => $this->uid]);

                return $file;
            }
        };
    }

    private function jobs(): JobProcessingRepository
    {
        return new class extends JobProcessingRepository {
            public int $nextUid = 100;
            /** @var array<int, array<string, mixed>> */
            public array $updates = [];
            public ?string $insertedType = null;

            public function __construct() {}

            public function insertArtifact(int $jobUid, ArtifactType $type, string $variant, int $fileUid, ArtifactStatus $status, ?string $error = null): int
            {
                $this->insertedType = $type->value;

                return $this->nextUid;
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
            public function check(int $beUserUid, float $plannedCost = 0.0): BudgetCheckResult
            {
                return BudgetCheckResult::allowed();
            }
        };
    }

    private function denyingBudget(): BudgetServiceInterface
    {
        return new class implements BudgetServiceInterface {
            public function check(int $beUserUid, float $plannedCost = 0.0): BudgetCheckResult
            {
                return BudgetCheckResult::denied('LIMIT_DAILY', 10.0, 10.0, 'exhausted');
            }
        };
    }

    public function testHappyPathSynthesizesAlternatingVoicesStitchesAndRecordsArtifact(): void
    {
        $completion = $this->completion();
        $speech = $this->speech();
        $stitcher = $this->stitcher();
        $storage = $this->storage();
        $jobs = $this->jobs();

        $generator = new PodcastGenerator(
            $jobs, $this->allowingBudget(), new NullLogger(), $completion, $speech, $stitcher, $storage, new WebVttBuilder(),
        );

        self::assertTrue($generator->generate($this->context()));
        self::assertSame('podcast', $jobs->insertedType);
        self::assertSame(['nova', 'onyx', 'nova'], array_column($speech->calls, 'voice'));
        self::assertSame(array_column($speech->calls, 'path'), $stitcher->concatInput);
        self::assertSame(['podcast.mp3', 'podcast.vtt'], $storage->order);

        $update = $jobs->updates[100];
        self::assertSame(1, (int) $update['file_uid']);
        self::assertSame(2, (int) $update['subtitle_file_uid']);
        self::assertStringContainsString('Host A: Welcome to the show.', $update['script_text']);
        self::assertStringContainsString('Host B: Glad to be here.', $update['script_text']);
        self::assertSame('done', $update['status']);
        self::assertSame('json', $completion->seenOptions->getResponseFormat());
        self::assertSame(3, $completion->seenOptions->getBeUserUid());
    }

    public function testWebVttCuesUseProbedDurations(): void
    {
        $storage = $this->storage();
        $generator = new PodcastGenerator(
            $this->jobs(), $this->allowingBudget(), new NullLogger(), $this->completion(), $this->speech(), $this->stitcher(), $storage, new WebVttBuilder(),
        );

        $generator->generate($this->context());

        $vtt = $storage->contentByName['podcast.vtt'];
        self::assertStringContainsString('00:00:00.000 --> 00:00:03.000', $vtt);
        self::assertStringContainsString('00:00:03.000 --> 00:00:05.000', $vtt);
        self::assertStringContainsString('00:00:05.000 --> 00:00:06.000', $vtt);
    }

    public function testOverBudgetMarksArtifactFailedWithoutTtsCalls(): void
    {
        $speech = $this->speech();
        $jobs = $this->jobs();

        $generator = new PodcastGenerator(
            $jobs, $this->denyingBudget(), new NullLogger(), $this->completion(), $speech, $this->stitcher(), $this->storage(), new WebVttBuilder(),
        );

        self::assertFalse($generator->generate($this->context()));
        self::assertSame([], $speech->calls);
        self::assertSame('failed', $jobs->updates[100]['status']);
    }

    public function testTtsUnavailableMarksArtifactFailed(): void
    {
        $speech = $this->speech();
        $speech->available = false;
        $jobs = $this->jobs();

        $generator = new PodcastGenerator(
            $jobs, $this->allowingBudget(), new NullLogger(), $this->completion(), $speech, $this->stitcher(), $this->storage(), new WebVttBuilder(),
        );

        self::assertFalse($generator->generate($this->context()));
        self::assertSame([], $speech->calls);
        self::assertSame('failed', $jobs->updates[100]['status']);
    }

    public function testSupportsReadsWantPodcastFlag(): void
    {
        $generator = new PodcastGenerator(
            $this->jobs(), $this->allowingBudget(), new NullLogger(), $this->completion(), $this->speech(), $this->stitcher(), $this->storage(), new WebVttBuilder(),
        );

        self::assertTrue($generator->supports($this->context(1)));
        self::assertFalse($generator->supports($this->context(0)));
    }
}
