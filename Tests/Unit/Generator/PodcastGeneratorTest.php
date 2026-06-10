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
use Netresearch\NrRepurpose\Domain\ValueObject\Persona;
use Netresearch\NrRepurpose\Domain\ValueObject\ResolvedPromptSnippets;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\PodcastGenerator;
use Netresearch\NrRepurpose\Generator\Speech\SpeechSynthesizerInterface;
use Netresearch\NrRepurpose\Generator\Support\WebVttBuilder;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Pipeline\JobProgress;
use Netresearch\NrRepurpose\Rendering\AudioStitcherInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StatusRecordingJobRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\File;

final class PodcastGeneratorTest extends TestCase
{
    /** @param list<Persona> $personas */
    private function context(int $wantPodcast = 1, array $personas = []): GenerationContext
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
            new ResolvedPromptSnippets(personas: $personas),
        );
    }

    /** @param list<array{speaker: string, text: string}>|null $turns */
    private function completion(?array $turns = null): CompletionServiceInterface
    {
        $turns ??= [
            ['speaker' => 'Host A', 'text' => 'Welcome to the show.'],
            ['speaker' => 'Host B', 'text' => 'Glad to be here.'],
            ['speaker' => 'Host A', 'text' => 'Lets dig in.'],
        ];

        return new class($turns) implements CompletionServiceInterface {
            public ?ChatOptions $seenOptions = null;
            public string $seenPrompt = '';

            /** @param list<array{speaker: string, text: string}> $turns */
            public function __construct(private readonly array $turns) {}

            public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
            {
                throw new \LogicException('not used');
            }

            public function completeJson(string $prompt, ?ChatOptions $options = null): array
            {
                $this->seenPrompt = $prompt;
                $this->seenOptions = $options;

                return ['turns' => $this->turns];
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

    public function testPersonaDialogueUsesPersonaNamesMetadataVoicesAndRoundRobinFallback(): void
    {
        $personas = [
            new Persona('Anna', 'Curious analyst who asks sharp questions.', 'fable'),
            new Persona('Ben', 'Skeptic who challenges every claim.', 'definitely-not-a-voice'),
            new Persona('Cara', 'Moderator keeping the pace.'),
        ];
        $completion = $this->completion([
            ['speaker' => 'Anna', 'text' => 'Welcome everyone.'],
            ['speaker' => 'Ben', 'text' => 'I have my doubts.'],
            ['speaker' => 'Cara', 'text' => 'Let us structure this.'],
            ['speaker' => 'Dave', 'text' => 'Not on the guest list.'],
        ]);
        $speech = $this->speech();
        $jobs = $this->jobs();

        $generator = new PodcastGenerator(
            $jobs, $this->allowingBudget(), new NullLogger(), $completion, $speech, $this->stitcher(), $this->storage(), new WebVttBuilder(),
        );

        self::assertTrue($generator->generate($this->context(1, $personas)));

        // The dialogue prompt describes each persona; the JSON shape pins the persona names as speakers.
        self::assertStringContainsString('- Anna: Curious analyst who asks sharp questions.', $completion->seenPrompt);
        self::assertStringContainsString('- Cara: Moderator keeping the pace.', $completion->seenPrompt);
        self::assertStringContainsString('"Anna"|"Ben"|"Cara"', (string) $completion->seenOptions?->getSystemPrompt());

        // Voice mapping: valid metadata voice wins (Anna); invalid/missing fall back to the host
        // voices round-robin (Ben = index 1 -> onyx, Cara = index 2 -> nova). The unknown speaker
        // "Dave" is attributed to the first persona and synthesized with its voice.
        self::assertSame(['fable', 'onyx', 'nova', 'fable'], array_column($speech->calls, 'voice'));

        $update = $jobs->updates[100];
        self::assertStringContainsString('Anna: Welcome everyone.', $update['script_text']);
        self::assertStringContainsString('Anna: Not on the guest list.', $update['script_text']);
        $metadata = json_decode((string) $update['metadata'], true);
        self::assertSame(['fable', 'onyx', 'nova'], $metadata['voices']);
        self::assertSame(['Anna', 'Ben', 'Cara'], $metadata['personas']);
    }

    public function testPersonaNamesWithQuotesAreJsonEscapedInTheSpeakerConstraint(): void
    {
        $personas = [
            new Persona('Jo "The Quant" Lee', 'Numbers person.', 'fable'),
            new Persona('Back\\slash', 'Edge-case fan.'),
        ];
        $completion = $this->completion([
            ['speaker' => 'Jo "The Quant" Lee', 'text' => 'Numbers first.'],
        ]);

        $generator = new PodcastGenerator(
            $this->jobs(), $this->allowingBudget(), new NullLogger(), $completion, $this->speech(), $this->stitcher(), $this->storage(), new WebVttBuilder(),
        );

        self::assertTrue($generator->generate($this->context(1, $personas)));

        // Each name is JSON-encoded, so quotes/backslashes cannot malform the shape constraint.
        self::assertStringContainsString(
            '{"turns":[{"speaker":"Jo \"The Quant\" Lee"|"Back\\\\slash","text":"..."}]}',
            (string) $completion->seenOptions?->getSystemPrompt(),
        );
    }

    public function testWithoutPersonasDialogueKeepsTheTwoHostShape(): void
    {
        $completion = $this->completion();
        $jobs = $this->jobs();

        $generator = new PodcastGenerator(
            $jobs, $this->allowingBudget(), new NullLogger(), $completion, $this->speech(), $this->stitcher(), $this->storage(), new WebVttBuilder(),
        );

        self::assertTrue($generator->generate($this->context()));
        self::assertStringNotContainsString('Hosts:', $completion->seenPrompt);
        self::assertStringContainsString('"speaker":"Host A"|"Host B"', (string) $completion->seenOptions?->getSystemPrompt());

        $metadata = json_decode((string) $jobs->updates[100]['metadata'], true);
        self::assertSame(['nova', 'onyx'], $metadata['voices']);
        self::assertArrayNotHasKey('personas', $metadata);
    }

    public function testReportsScriptVoicingAndStitchingProgressSteps(): void
    {
        $progressJobs = new StatusRecordingJobRepository();
        $generator = new PodcastGenerator(
            $this->jobs(), $this->allowingBudget(), new NullLogger(), $this->completion(), $this->speech(), $this->stitcher(), $this->storage(), new WebVttBuilder(),
        );
        $ctx = $this->context()->withProgress(new JobProgress($progressJobs, 7, 30.0, 100.0));

        self::assertTrue($generator->generate($ctx));
        self::assertSame([
            'Podcast: writing script',
            'Podcast: voicing segment 1/3',
            'Podcast: voicing segment 2/3',
            'Podcast: voicing segment 3/3',
            'Podcast: stitching audio',
        ], $progressJobs->steps());

        // Progress only ever moves forward within the generator's band.
        $progresses = $progressJobs->progresses();
        $sorted = $progresses;
        sort($sorted);
        self::assertSame($sorted, $progresses);
        self::assertGreaterThanOrEqual(30, min($progresses));
        self::assertLessThanOrEqual(100, max($progresses));
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
