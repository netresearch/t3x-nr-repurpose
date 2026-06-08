# nr_repurpose Plan 5 — Real Generators Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Follow strict TDD: write the failing test, run it to confirm the FAIL, implement the complete real code, run the test to confirm PASS, then commit with `git commit -s` (no AI/bot attribution, English message).

**Goal:** Replace the walking-skeleton `StubArtifactGenerator` with the three real artifact generators — `PodcastGenerator`, `SchaubildGenerator`, `StoryGenerator` — each implementing the `GenerationContext` variant of `ArtifactGeneratorInterface`. The podcast produces an mp3 (stitched two-host TTS), a speaker-tagged transcript and a WebVTT subtitle file. The Schaubild produces three artifact rows (`html`, `html_bg`, `ki_image`). The Story produces one 9:16 PNG. All Specialized nr-llm calls (TTS, FAL image) are budget-guarded and availability-guarded; render/ffmpeg/Poppler/nr-llm services sit behind interfaces and are faked in unit tests.

**Architecture:** Generators live in `Netresearch\NrRepurpose\Generator\` and consume the `GenerationContext` VO (from Plan 3). Each generator: (1) builds a prompt from the `ContentBrief`, (2) calls a budget-guarded nr-llm Feature service (`CompletionService`) for text/HTML, (3) calls a budget-guarded Specialized service (`TextToSpeechService`/`FalImageService`) for media, (4) renders branded Fluid templates to HTML via a `StandaloneView`, (5) hands HTML to the Plan-4 `HtmlToImageRendererInterface`, composites/stitches via the Plan-4 `ImageCompositorInterface`/`AudioStitcherInterface`, (6) stores bytes via `JobFileStorage` (Plan 1) and records artifact rows via `JobProcessingRepository` (Plan 1, extended here with `updateArtifact`). Per-artifact isolation: a generator never throws for a single-artifact business failure — it records a `failed` artifact row and returns `false` so siblings continue (the orchestrator from Plan 3 already enforces this).

**Tech Stack:** PHP 8.3+, `declare(strict_types=1)`, final classes, constructor property promotion, readonly VOs, typed properties (PSR-12 / TYPO3 CGL). TYPO3 v14.3 LTS, `TYPO3\CMS\Fluid\View\StandaloneView` for template rendering. nr-llm Feature service `CompletionService` (budget-aware via `ChatOptions->withBeUserUid()`), Specialized services `TextToSpeechService` + `FalImageService` (NOT auto budget-guarded — guarded manually via `BudgetService::check()` + `isAvailable()`). Plan-4 render interfaces. `typo3/testing-framework` (unit + functional). Tests: unit via host `.Build/bin/phpunit -c Build/phpunit/UnitTests.xml`; functional via `ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml"`.

**Spec coverage (this plan):** §8 Schaubild (three variants `html`/`html_bg`/`ki_image`), §9 Podcast (two-host dialogue, transcript in `script_text`, WebVTT from per-segment ffprobe durations, mp3 + vtt in FAL), §10 Instagram Story (9:16 1080×1920 PNG, optional KI background), §7 Generators + nr-llm integration table, §13 theme templates (`Resources/Private/Templates/Generated/{Schaubild,Story}/{Nr,Neutral}.html`, NR-CI + neutral). Cross-plan contracts: `JobProcessingRepository::updateArtifact`, Budget/Capability gating, capability perm options in `ext_localconf.php`, Services.yaml `nr_repurpose.artifact_generator` tagging. NOT in this plan: ingestion (Plan 2), understanding/orchestrator evolution (Plan 3), render interfaces themselves (Plan 4), BE result-view polish (Plan 6).

**Dependencies (must exist before this plan runs):**
- **Plan 1 (walking skeleton):** `JobProcessingRepository` (`findRow`, `markStatus`, `markFailed`, `insertArtifact`), `JobFileStorage::store(string, string): File`, `ArtifactType`/`ArtifactStatus` enums, `tx_nrrepurpose_domain_model_artifact` columns `file_uid`, `subtitle_file_uid`, `source_html`, `script_text`, `status`, `error_message`, `metadata`, the `ArtifactGeneratorInterface` (Plan-1 array signature), `StubArtifactGenerator`, `GenerationOrchestrator`.
- **Plan 2:** `Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument`.
- **Plan 3:** `Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief`, `Netresearch\NrRepurpose\Pipeline\GenerationContext`, the **evolved** `ArtifactGeneratorInterface` (`supports(GenerationContext): bool` / `generate(GenerationContext): bool`), and the orchestrator that calls `supports()`/`generate()` per generator.
- **Plan 4:** `Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface`, `ImageCompositorInterface`, `AudioStitcherInterface` (+ implementations `PlaywrightHtmlToImageRenderer`, `GdImageCompositor`, `FfmpegAudioStitcher`).

**Key grounded facts** (see `docs/superpowers/grounding/2026-06-08-cross-stack-api-grounding.md`):
- `CompletionServiceInterface` is public/aliased in nr-llm Services.yaml ([1]); `completeJson(string $prompt, ?ChatOptions $options = null): array` decodes JSON and throws `InvalidArgumentException` on bad JSON ([3]); `completeMarkdown(...): string` returns a string ([3]). System prompt + JSON mode + budget guard via `ChatOptions(temperature:, responseFormat:'json', systemPrompt:, beUserUid:, plannedCost:)` ([4],[18]); `withBeUserUid()` enables the `BudgetMiddleware` guard automatically ([17],[18]).
- `TextToSpeechService` is a **public concrete class, no interface, NOT budget-guarded** ([1],[9],[17]). `synthesizeToFile(string $text, string $outputPath, SpeechSynthesisOptions|array $options = []): SpeechSynthesisResult` ([9], max 4096 chars/turn). `SpeechSynthesisOptions(model:'tts-1', voice:'alloy'[alloy|echo|fable|onyx|nova|shimmer], format:'mp3'[mp3|opus|aac|flac|wav|pcm], speed:1.0)` ([10]). `isAvailable(): bool` is true only when the OpenAI key is in nr-llm config ([15]).
- `FalImageService` is a **public concrete class, no interface, NOT budget-guarded** ([1],[11],[17]). `generate(string $prompt, string $model='flux-schnell', array $options=[]): ImageGenerationResult` ([11]); `imageToImage(string $imageUrl, string $prompt, string $model='flux-dev', array $options=[]): ImageGenerationResult` — `$imageUrl` is a URL or `data:` URI; local PNG must be base64-data-URI-wrapped ([11],[12]). `ImageGenerationResult::saveToFile(string $path): bool` downloads from `$url` when base64 is null ([14]). `isAvailable(): bool` ([15]).
- `BudgetService::check(int $beUserUid, float $plannedCost = 0.0): BudgetCheckResult` is a pure pre-flight check (no throw); guard with `if (!$this->budget->check($uid, $cost)->allowed) { ... }` ([16]). The interface `BudgetServiceInterface` is public/aliased ([1],[16]).
- `ModelCapability` has cases `AUDIO='audio'` and `VISION='vision'` — there is **no IMAGE/SPEECH capability** ([20]). Capability perm options register in `ext_localconf.php` under `$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']` ([19]).
- Plan-4 render contract: `HtmlToImageRendererInterface::render(string $html, int $width, ?int $height, float $deviceScaleFactor = 1.0, bool $transparent = false): string` returns an absolute PNG path; `$height=null` => auto-height fullPage; `$transparent=true` requires the CSS to set `html,body{background:transparent}`. `ImageCompositorInterface::overlay(string $backgroundPng, string $foregroundPng, string $outPath): string`. `AudioStitcherInterface::concat(array $mp3Paths, string $outPath): string` + `probeDurationSeconds(string $path): float`.
- The render `render.cjs` waits for `networkidle` + `document.fonts.ready`, so Google-Fonts `@import` in the template CSS is resolved before the screenshot.

---

## File Structure

**Extension repo root = `/home/sme/p/nr-repurpose/main/`** (paths below are relative to it).

| File | Responsibility |
|---|---|
| `Classes/Persistence/JobProcessingRepository.php` | (MODIFY) add `updateArtifact(int $artifactUid, array $fields): void` — sets `subtitle_file_uid`/`source_html`/`script_text`/`metadata`/`status` etc. |
| `Classes/Generator/AbstractGenerator.php` | Shared base: budget+availability guard helper, fail-artifact helper, StandaloneView template render, temp-dir helper |
| `Classes/Generator/PodcastGenerator.php` | Build two-host dialogue (CompletionService JSON) → TTS per turn (nova/onyx) → stitch mp3 → transcript + WebVTT → store + record (`type=podcast`) |
| `Classes/Generator/SchaubildGenerator.php` | Build branded diagram HTML (CompletionService + StandaloneView) → 3 artifact rows: `html` (render opaque PNG), `html_bg` (FAL bg + transparent render + overlay), `ki_image` (render opaque → data URI → FAL `imageToImage`) |
| `Classes/Generator/StoryGenerator.php` | Build 9:16 HTML (CompletionService + StandaloneView) → render(1080,1920,opaque) PNG, optional KI background → record (`type=story`) |
| `Classes/Generator/Support/WebVttBuilder.php` | Pure helper: turn `list<{speaker,text,durationSeconds}>` into a WebVTT string (cue math) |
| `Classes/Generator/Support/DialogueTurn.php` | readonly VO: `speaker`, `text`, `voice` |
| `Resources/Private/Templates/Generated/Schaubild/Nr.html` | NR-CI diagram Fluid template (branding colors, Raleway/Open Sans), transparent-aware |
| `Resources/Private/Templates/Generated/Schaubild/Neutral.html` | Neutral/white-label diagram Fluid template, transparent-aware |
| `Resources/Private/Templates/Generated/Story/Nr.html` | NR-CI 9:16 story Fluid template, transparent-aware |
| `Resources/Private/Templates/Generated/Story/Neutral.html` | Neutral 9:16 story Fluid template, transparent-aware |
| `ext_localconf.php` | (MODIFY) register capability perm options mapped to `ModelCapability::AUDIO`/`VISION` |
| `Configuration/Services.yaml` | (MODIFY) tag the three generators `nr_repurpose.artifact_generator`; stop tagging `StubArtifactGenerator` |
| `Tests/Functional/Persistence/JobProcessingRepositoryUpdateArtifactTest.php` | Functional test for `updateArtifact` |
| `Tests/Unit/Generator/Support/WebVttBuilderTest.php` | Unit test: WebVTT cue math from durations |
| `Tests/Unit/Generator/PodcastGeneratorTest.php` | Unit test: prompts, budget/availability branch, transcript, stitch order, vtt, artifact write — all nr-llm/render faked |
| `Tests/Unit/Generator/SchaubildGeneratorTest.php` | Unit test: three variants produced, budget guard on FAL, source_html stored — faked deps |
| `Tests/Unit/Generator/StoryGeneratorTest.php` | Unit test: 9:16 render call, optional KI bg, artifact write — faked deps |
| `Tests/Functional/Generator/GeneratorRegistrationTest.php` | Functional test: the three generators are tagged & injected, stub is not in the tagged set |

> **DI note:** The three generators are autowired. `CompletionServiceInterface`, `BudgetServiceInterface` are public nr-llm interfaces — autowire as-is. `TextToSpeechService` and `FalImageService` are public concrete nr-llm classes — type-hint the concrete class. `HtmlToImageRendererInterface`, `ImageCompositorInterface`, `AudioStitcherInterface` are this extension's own interfaces (Plan 4) aliased to their implementations in `Configuration/Services.yaml`.

---

## Task 1: Extend `JobProcessingRepository` with `updateArtifact`

**Files:**
- Modify: `Classes/Persistence/JobProcessingRepository.php`
- Test: `Tests/Functional/Persistence/JobProcessingRepositoryUpdateArtifactTest.php`

- [ ] **Step 1: Write the failing functional test `Tests/Functional/Persistence/JobProcessingRepositoryUpdateArtifactTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Persistence;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class JobProcessingRepositoryUpdateArtifactTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-repurpose'];

    private function seedJob(): int
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid' => 0,
            'source_type' => 'url',
            'source_value' => 'https://example.com/',
            'theme' => 'nr',
            'want_podcast' => 1,
            'want_schaubild' => 1,
            'want_story' => 1,
            'status' => 'queued',
        ]);

        return (int) $conn->lastInsertId();
    }

    public function testUpdateArtifactWritesAllProvidedFields(): void
    {
        $jobUid = $this->seedJob();
        $repo = $this->get(JobProcessingRepository::class);

        // Generators insert a pending artifact first, then fill it.
        $artifactUid = $repo->insertArtifact($jobUid, ArtifactType::Podcast, 'default', 0, ArtifactStatus::Pending);

        $repo->updateArtifact($artifactUid, [
            'file_uid' => 42,
            'subtitle_file_uid' => 99,
            'source_html' => '<html>diagram</html>',
            'script_text' => "Host A: hello\nHost B: hi",
            'metadata' => '{"voice":"nova"}',
            'status' => ArtifactStatus::Done->value,
        ]);

        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact');
        $row = $conn->select(['*'], 'tx_nrrepurpose_domain_model_artifact', ['uid' => $artifactUid])
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame(42, (int) $row['file_uid']);
        self::assertSame(99, (int) $row['subtitle_file_uid']);
        self::assertSame('<html>diagram</html>', $row['source_html']);
        self::assertSame("Host A: hello\nHost B: hi", $row['script_text']);
        self::assertSame('{"voice":"nova"}', $row['metadata']);
        self::assertSame('done', $row['status']);
    }

    public function testUpdateArtifactWithEmptyFieldsIsNoOp(): void
    {
        $jobUid = $this->seedJob();
        $repo = $this->get(JobProcessingRepository::class);
        $artifactUid = $repo->insertArtifact($jobUid, ArtifactType::Story, 'default', 0, ArtifactStatus::Pending);

        $repo->updateArtifact($artifactUid, []);

        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact');
        $row = $conn->select(['status'], 'tx_nrrepurpose_domain_model_artifact', ['uid' => $artifactUid])
            ->fetchAssociative();

        self::assertSame('pending', $row['status']);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter JobProcessingRepositoryUpdateArtifactTest"`
Expected: FAIL — `Error: Call to undefined method Netresearch\NrRepurpose\Persistence\JobProcessingRepository::updateArtifact()`.

- [ ] **Step 3: Add `updateArtifact` to `Classes/Persistence/JobProcessingRepository.php`**

Insert the following method into the existing `JobProcessingRepository` class (after `insertArtifact`). Only the whitelisted columns are writable; an empty `$fields` is a no-op; `tstamp` is always refreshed when any field is written.

```php
    /**
     * Fill a previously-inserted (pending) artifact row. Only whitelisted columns are writable.
     * Empty $fields is a no-op (no UPDATE issued).
     *
     * @param array<string, mixed> $fields keys: file_uid, subtitle_file_uid, source_html,
     *                                      script_text, metadata, status, variant, error_message
     */
    public function updateArtifact(int $artifactUid, array $fields): void
    {
        $allowed = [
            'file_uid', 'subtitle_file_uid', 'source_html',
            'script_text', 'metadata', 'status', 'variant', 'error_message',
        ];
        $update = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $fields)) {
                $update[$column] = $fields[$column];
            }
        }
        if ($update === []) {
            return;
        }
        $update['tstamp'] = time();

        $this->connectionPool->getConnectionForTable(self::ARTIFACT_TABLE)
            ->update(self::ARTIFACT_TABLE, $update, ['uid' => $artifactUid]);
    }
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter JobProcessingRepositoryUpdateArtifactTest"`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add Classes/Persistence/JobProcessingRepository.php Tests/Functional/Persistence/JobProcessingRepositoryUpdateArtifactTest.php
git commit -s -m "Add JobProcessingRepository::updateArtifact for generator artifact fills"
```

---

## Task 2: WebVTT cue builder + dialogue VO (pure unit-testable helpers)

**Files:**
- Create: `Classes/Generator/Support/DialogueTurn.php`
- Create: `Classes/Generator/Support/WebVttBuilder.php`
- Test: `Tests/Unit/Generator/Support/WebVttBuilderTest.php`

- [ ] **Step 1: Write the failing unit test `Tests/Unit/Generator/Support/WebVttBuilderTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Support;

use Netresearch\NrRepurpose\Generator\Support\WebVttBuilder;
use PHPUnit\Framework\TestCase;

final class WebVttBuilderTest extends TestCase
{
    public function testCueTimesAccumulateFromSegmentDurations(): void
    {
        $builder = new WebVttBuilder();

        $vtt = $builder->build([
            ['speaker' => 'Host A', 'text' => 'Welcome to the show.', 'durationSeconds' => 3.5],
            ['speaker' => 'Host B', 'text' => 'Glad to be here.', 'durationSeconds' => 2.25],
        ]);

        $expected = "WEBVTT\n\n"
            . "1\n"
            . "00:00:00.000 --> 00:00:03.500\n"
            . "Host A: Welcome to the show.\n\n"
            . "2\n"
            . "00:00:03.500 --> 00:00:05.750\n"
            . "Host B: Glad to be here.\n";

        self::assertSame($expected, $vtt);
    }

    public function testTimestampFormattingCrossesMinuteAndHourBoundaries(): void
    {
        $builder = new WebVttBuilder();

        $vtt = $builder->build([
            ['speaker' => 'Host A', 'text' => 'Long intro.', 'durationSeconds' => 3661.0],
            ['speaker' => 'Host B', 'text' => 'Reply.', 'durationSeconds' => 1.0],
        ]);

        self::assertStringContainsString("00:00:00.000 --> 01:01:01.000", $vtt);
        self::assertStringContainsString("01:01:01.000 --> 01:01:02.000", $vtt);
    }

    public function testEmptyDialogueProducesHeaderOnly(): void
    {
        $builder = new WebVttBuilder();

        self::assertSame("WEBVTT\n", $builder->build([]));
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter WebVttBuilderTest`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Generator\Support\WebVttBuilder" not found`.

- [ ] **Step 3: Write `Classes/Generator/Support/DialogueTurn.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

/**
 * One spoken turn of the two-host podcast dialogue. The voice is resolved by the
 * generator from the speaker label (Host A => nova, Host B => onyx by default).
 */
final readonly class DialogueTurn
{
    public function __construct(
        public string $speaker,
        public string $text,
        public string $voice,
    ) {}
}
```

- [ ] **Step 4: Write `Classes/Generator/Support/WebVttBuilder.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

/**
 * Builds a WebVTT subtitle document from a sequence of dialogue segments and their
 * measured (ffprobe) durations. Cue start = sum of all prior durations; no provider call.
 */
final class WebVttBuilder
{
    /**
     * @param list<array{speaker: string, text: string, durationSeconds: float}> $segments
     */
    public function build(array $segments): string
    {
        $out = 'WEBVTT' . "\n";
        $cursor = 0.0;
        $index = 1;

        foreach ($segments as $segment) {
            $start = $cursor;
            $end = $cursor + $segment['durationSeconds'];
            $out .= "\n" . $index . "\n";
            $out .= $this->formatTimestamp($start) . ' --> ' . $this->formatTimestamp($end) . "\n";
            $out .= $segment['speaker'] . ': ' . $segment['text'] . "\n";
            $cursor = $end;
            $index++;
        }

        return $out;
    }

    private function formatTimestamp(float $seconds): string
    {
        $milliseconds = (int) round($seconds * 1000);
        $hours = intdiv($milliseconds, 3_600_000);
        $milliseconds -= $hours * 3_600_000;
        $minutes = intdiv($milliseconds, 60_000);
        $milliseconds -= $minutes * 60_000;
        $secs = intdiv($milliseconds, 1000);
        $milliseconds -= $secs * 1000;

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $milliseconds);
    }
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter WebVttBuilderTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add Classes/Generator/Support Tests/Unit/Generator/Support
git commit -s -m "Add WebVttBuilder and DialogueTurn support types for the podcast generator"
```

---

## Task 3: `AbstractGenerator` base (shared guard/render/fail helpers)

**Files:**
- Create: `Classes/Generator/AbstractGenerator.php`

> No standalone test — every concrete helper is exercised through the three generator unit tests (Tasks 4–6). This task only introduces the shared base; the next failing test (Task 4 Step 1) drives the first real use.

- [ ] **Step 1: Write `Classes/Generator/AbstractGenerator.php`**

The base provides: a budget+availability guard for Specialized calls; a `failArtifact` helper that records an artifact row as `failed`; a StandaloneView template render to HTML string; and a per-run temp directory. Concrete generators implement `supports()` and `generate()`.

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Shared base for the real artifact generators. Provides Specialized-call guarding
 * (budget + availability), Fluid StandaloneView rendering of the branded theme templates,
 * a per-run temp directory and a uniform failed-artifact helper.
 *
 * Concrete generators MUST NOT throw for a single-artifact business failure: record the
 * artifact as failed and return false so sibling generators keep running.
 */
abstract class AbstractGenerator implements ArtifactGeneratorInterface
{
    public function __construct(
        protected readonly JobProcessingRepository $jobs,
        protected readonly BudgetServiceInterface $budget,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * Guard a Specialized nr-llm call (TTS/FAL) which is NOT covered by the budget middleware.
     * Returns true when the planned cost is within budget AND the service is available.
     */
    protected function specializedAllowed(GenerationContext $ctx, float $plannedCost, bool $serviceAvailable): bool
    {
        if (!$this->budget->check($ctx->beUser, $plannedCost)->allowed) {
            return false;
        }

        return $serviceAvailable;
    }

    /**
     * Render one of the branded theme templates to an HTML string.
     *
     * @param array<string, mixed> $variables
     */
    protected function renderTemplate(string $area, string $theme, array $variables): string
    {
        $templateName = $theme === 'nr' ? 'Nr' : 'Neutral';
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplatePathAndFilename(
            GeneralUtility::getFileAbsFileName(
                sprintf('EXT:nr_repurpose/Resources/Private/Templates/Generated/%s/%s.html', $area, $templateName),
            ),
        );
        $view->assignMultiple($variables);

        return $view->render();
    }

    /** Absolute path to a fresh, writable per-run temp directory (auto-created). */
    protected function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/nrrepurpose_' . bin2hex(random_bytes(8));
        GeneralUtility::mkdir_deep($dir);

        return $dir;
    }

    /** Record a previously-inserted artifact row as failed and log the reason. */
    protected function failArtifact(int $artifactUid, int $jobUid, string $reason): void
    {
        $this->logger->warning('Artifact generation failed', [
            'job' => $jobUid,
            'artifact' => $artifactUid,
            'reason' => $reason,
        ]);
        $this->jobs->updateArtifact($artifactUid, [
            'status' => \Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus::Failed->value,
            'error_message' => $reason,
        ]);
    }
}
```

- [ ] **Step 2: Lint the new file**

Run: `cd /home/sme/p/nr-repurpose/main && php -l Classes/Generator/AbstractGenerator.php`
Expected: `No syntax errors detected in Classes/Generator/AbstractGenerator.php`.

- [ ] **Step 3: Commit**

```bash
git add Classes/Generator/AbstractGenerator.php
git commit -s -m "Add AbstractGenerator base with Specialized-call guard and template render"
```

---

## Task 4: `PodcastGenerator` (dialogue → TTS → stitch → transcript + WebVTT)

**Files:**
- Create: `Classes/Generator/PodcastGenerator.php`
- Test: `Tests/Unit/Generator/PodcastGeneratorTest.php`

- [ ] **Step 1: Write the failing unit test `Tests/Unit/Generator/PodcastGeneratorTest.php`**

The test fakes every dependency: `CompletionServiceInterface` returns a fixed JSON dialogue; `TextToSpeechService` is a fake that writes a tiny file and records its calls; `AudioStitcherInterface` is faked to assert segment order and return fixed durations; `JobFileStorage` and `JobProcessingRepository` are faked to capture what was stored/recorded; `BudgetServiceInterface` is faked to flip allowed/denied.

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\PodcastGenerator;
use Netresearch\NrRepurpose\Generator\Support\WebVttBuilder;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Rendering\AudioStitcherInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;
use Netresearch\NrLlm\Specialized\Speech\SpeechSynthesisResult;
use Netresearch\NrLlm\Specialized\Speech\TextToSpeechService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\File;

final class PodcastGeneratorTest extends TestCase
{
    private function context(): GenerationContext
    {
        $document = new SourceDocument(
            title: 'Quarterly Report',
            text: 'Revenue grew. Costs fell.',
            sourceLabel: 'https://example.com/report',
            pageCount: 0,
            languageHint: 'en',
        );
        $brief = new ContentBrief(
            title: 'Quarterly Report',
            summary: 'Revenue up, costs down.',
            keyPoints: ['Revenue +12%', 'Costs -5%'],
            sections: [['heading' => 'Financials', 'body' => 'Details.']],
            audience: 'Investors',
            language: 'en',
        );

        return new GenerationContext(
            jobRow: ['uid' => 7, 'theme' => 'nr', 'be_user' => 3, 'want_podcast' => 1],
            document: $document,
            brief: $brief,
            theme: 'nr',
            beUser: 3,
        );
    }

    private function completionReturningDialogue(): CompletionServiceInterface
    {
        return new class implements CompletionServiceInterface {
            public ?ChatOptions $seenOptions = null;
            public string $seenPrompt = '';

            public function complete(string $prompt, ?ChatOptions $options = null): \Netresearch\NrLlm\Domain\Model\CompletionResponse
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

            public function completeFactual(string $prompt, ?ChatOptions $options = null): \Netresearch\NrLlm\Domain\Model\CompletionResponse
            {
                throw new \LogicException('not used');
            }

            public function completeCreative(string $prompt, ?ChatOptions $options = null): \Netresearch\NrLlm\Domain\Model\CompletionResponse
            {
                throw new \LogicException('not used');
            }
        };
    }

    /** TTS fake: writes a tiny file, records voice + output path order. */
    private function fakeTts(): TextToSpeechService
    {
        return new class extends TextToSpeechService {
            /** @var list<array{voice: string, path: string}> */
            public array $calls = [];
            public bool $available = true;

            public function __construct() {}

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function synthesizeToFile(string $text, string $outputPath, SpeechSynthesisOptions|array $options = []): SpeechSynthesisResult
            {
                $voice = $options instanceof SpeechSynthesisOptions ? ($options->toArray()['voice'] ?? '') : ($options['voice'] ?? '');
                file_put_contents($outputPath, 'AUDIO:' . $text);
                $this->calls[] = ['voice' => (string) $voice, 'path' => $outputPath];

                return new SpeechSynthesisResult('AUDIO', 'mp3', 'tts-1', (string) $voice, strlen($text), null);
            }
        };
    }

    private function fakeStitcher(): AudioStitcherInterface
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
                // Deterministic, distinguishable per segment for the cue-math assertion.
                return str_contains($path, 'turn-0.') ? 3.0 : (str_contains($path, 'turn-1.') ? 2.0 : 1.0);
            }
        };
    }

    /** @return array{0: JobFileStorage, 1: list<array{name: string}>} */
    private function fakeStorage(): array
    {
        $stored = [];
        $storage = new class($stored) extends JobFileStorage {
            /** @param list<array{name: string}> $stored */
            public function __construct(public array &$stored) {}

            public function store(string $content, string $fileName): File
            {
                $this->stored[] = ['name' => $fileName];
                $file = $this->createMockFile(count($this->stored));

                return $file;
            }

            private function createMockFile(int $uid): File
            {
                $file = (new \ReflectionClass(File::class))->newInstanceWithoutConstructor();
                $ref = new \ReflectionProperty(File::class, 'properties');
                $ref->setValue($file, ['uid' => $uid]);

                return $file;
            }
        };

        return [$storage, $stored];
    }

    private function recordingJobs(): JobProcessingRepository
    {
        return new class extends JobProcessingRepository {
            public int $nextUid = 100;
            /** @var array<int, array<string, mixed>> */
            public array $updates = [];
            public ?string $insertedType = null;

            public function __construct() {}

            public function insertArtifact(int $jobUid, \Netresearch\NrRepurpose\Domain\Enum\ArtifactType $type, string $variant, int $fileUid, \Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus $status, ?string $error = null): int
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

    public function testHappyPathSynthesizesAlternatingVoicesStitchesAndRecordsArtifact(): void
    {
        $completion = $this->completionReturningDialogue();
        $tts = $this->fakeTts();
        $stitcher = $this->fakeStitcher();
        [$storage, $stored] = $this->fakeStorage();
        $jobs = $this->recordingJobs();
        $budget = $this->allowingBudget();

        $generator = new PodcastGenerator(
            $jobs, $budget, new NullLogger(), $completion, $tts, $stitcher, $storage, new WebVttBuilder(),
        );

        $result = $generator->generate($this->context());

        self::assertTrue($result);
        self::assertSame('podcast', $jobs->insertedType);
        // 3 turns: nova, onyx, nova
        self::assertSame(['nova', 'onyx', 'nova'], array_column($tts->calls, 'voice'));
        // stitch order matches synthesis order
        self::assertSame(array_column($tts->calls, 'path'), $stitcher->concatInput);
        // stored an mp3 and a vtt
        self::assertSame(['podcast.mp3', 'podcast.vtt'], array_column($stored, 'name'));

        $update = $jobs->updates[100];
        self::assertSame(1, (int) $update['file_uid']);          // mp3 stored first
        self::assertSame(2, (int) $update['subtitle_file_uid']); // vtt stored second
        self::assertStringContainsString('Host A: Welcome to the show.', $update['script_text']);
        self::assertStringContainsString('Host B: Glad to be here.', $update['script_text']);
        self::assertSame('done', $update['status']);
        // JSON mode + budget user wired into completion options
        self::assertSame('json', $completion->seenOptions->responseFormat);
        self::assertSame(3, $completion->seenOptions->beUserUid);
    }

    public function testWebVttCuesUseProbedDurations(): void
    {
        $completion = $this->completionReturningDialogue();
        [$storage, $stored] = $this->fakeStorageCapturingContent();
        $jobs = $this->recordingJobs();

        $generator = new PodcastGenerator(
            $jobs, $this->allowingBudget(), new NullLogger(), $completion, $this->fakeTts(), $this->fakeStitcher(), $storage, new WebVttBuilder(),
        );

        $generator->generate($this->context());

        $vtt = $storage->contentByName['podcast.vtt'];
        // durations 3.0, 2.0, 1.0 => cues 0-3, 3-5, 5-6
        self::assertStringContainsString("00:00:00.000 --> 00:00:03.000", $vtt);
        self::assertStringContainsString("00:00:03.000 --> 00:00:05.000", $vtt);
        self::assertStringContainsString("00:00:05.000 --> 00:00:06.000", $vtt);
    }

    public function testOverBudgetMarksArtifactFailedWithoutTtsCalls(): void
    {
        $tts = $this->fakeTts();
        $jobs = $this->recordingJobs();
        [$storage] = $this->fakeStorage();

        $generator = new PodcastGenerator(
            $jobs, $this->denyingBudget(), new NullLogger(), $this->completionReturningDialogue(), $tts, $this->fakeStitcher(), $storage, new WebVttBuilder(),
        );

        $result = $generator->generate($this->context());

        self::assertFalse($result);
        self::assertSame([], $tts->calls);
        self::assertSame('failed', $jobs->updates[100]['status']);
    }

    public function testTtsUnavailableMarksArtifactFailed(): void
    {
        $tts = $this->fakeTts();
        $tts->available = false;
        $jobs = $this->recordingJobs();
        [$storage] = $this->fakeStorage();

        $generator = new PodcastGenerator(
            $jobs, $this->allowingBudget(), new NullLogger(), $this->completionReturningDialogue(), $tts, $this->fakeStitcher(), $storage, new WebVttBuilder(),
        );

        self::assertFalse($generator->generate($this->context()));
        self::assertSame([], $tts->calls);
        self::assertSame('failed', $jobs->updates[100]['status']);
    }

    public function testSupportsReadsWantPodcastFlag(): void
    {
        $generator = new PodcastGenerator(
            $this->recordingJobs(), $this->allowingBudget(), new NullLogger(), $this->completionReturningDialogue(), $this->fakeTts(), $this->fakeStitcher(), $this->fakeStorage()[0], new WebVttBuilder(),
        );

        self::assertTrue($generator->supports($this->context()));

        $ctx = new GenerationContext(
            jobRow: ['uid' => 7, 'theme' => 'nr', 'be_user' => 3, 'want_podcast' => 0],
            document: $this->context()->document,
            brief: $this->context()->brief,
            theme: 'nr',
            beUser: 3,
        );
        self::assertFalse($generator->supports($ctx));
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

    /** Storage fake that also captures written content by file name. */
    private function fakeStorageCapturingContent(): array
    {
        $storage = new class extends JobFileStorage {
            /** @var array<string, string> */
            public array $contentByName = [];
            private int $uid = 0;

            public function __construct() {}

            public function store(string $content, string $fileName): File
            {
                $this->contentByName[$fileName] = $content;
                $this->uid++;
                $file = (new \ReflectionClass(File::class))->newInstanceWithoutConstructor();
                $ref = new \ReflectionProperty(File::class, 'properties');
                $ref->setValue($file, ['uid' => $this->uid]);

                return $file;
            }
        };

        return [$storage, &$storage->contentByName];
    }
}
```

> The exact constructor signature of `BudgetCheckResult::denied()` and `SpeechSynthesisResult::__construct()` is taken from grounding [16] (`public bool $allowed, string $exceededLimit, float $currentUsage, float $limit, string $reason`) and [10] (`audioContent, format, model, voice, characterCount, ?metadata`). If a constructor arg count differs at implementation time, read the nr-llm source at the cited path and adjust the fake call only — the generator under test is unaffected.

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter PodcastGeneratorTest`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Generator\PodcastGenerator" not found`.

- [ ] **Step 3: Write `Classes/Generator/PodcastGenerator.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Generator\Support\WebVttBuilder;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Rendering\AudioStitcherInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;
use Netresearch\NrLlm\Specialized\Speech\TextToSpeechService;
use Psr\Log\LoggerInterface;

/**
 * Builds a two-host podcast: an LLM dialogue script (length follows document scope),
 * one TTS call per turn (Host A=nova, Host B=onyx, mp3), a stitched mp3, a speaker-tagged
 * transcript and a WebVTT subtitle file whose cue times come from measured segment durations.
 *
 * The dialogue script is generated by the budget-aware CompletionService (the BudgetMiddleware
 * guards it via ChatOptions->beUserUid). Each TTS call is a Specialized call that is NOT
 * middleware-guarded, so it is gated manually by BudgetService::check() + isAvailable().
 */
final class PodcastGenerator extends AbstractGenerator
{
    private const VOICE_HOST_A = 'nova';
    private const VOICE_HOST_B = 'onyx';
    private const TTS_COST_PER_TURN = 0.015;
    private const SCRIPT_COST = 0.02;

    public function __construct(
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
        LoggerInterface $logger,
        private readonly CompletionServiceInterface $completion,
        private readonly TextToSpeechService $tts,
        private readonly AudioStitcherInterface $stitcher,
        private readonly JobFileStorage $fileStorage,
        private readonly WebVttBuilder $vttBuilder,
    ) {
        parent::__construct($jobs, $budget, $logger);
    }

    public function supports(GenerationContext $ctx): bool
    {
        return (bool) ($ctx->jobRow['want_podcast'] ?? false);
    }

    public function generate(GenerationContext $ctx): bool
    {
        $jobUid = $ctx->jobUid();
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Podcast, 'default', 0, ArtifactStatus::Pending);

        // 1. Budget-guarded availability check for the Specialized TTS calls up front.
        $turnCount = max(1, count($ctx->brief->keyPoints) + count($ctx->brief->sections) + 2);
        $plannedTtsCost = self::TTS_COST_PER_TURN * $turnCount;
        if (!$this->specializedAllowed($ctx, $plannedTtsCost, $this->tts->isAvailable())) {
            $this->failArtifact($artifactUid, $jobUid, 'AI budget exhausted or speech synthesis unavailable');

            return false;
        }

        try {
            $turns = $this->buildDialogue($ctx);
            if ($turns === []) {
                $this->failArtifact($artifactUid, $jobUid, 'LLM produced no dialogue turns');

                return false;
            }

            $tmpDir = $this->makeTempDir();
            $segmentPaths = [];
            $vttSegments = [];
            $transcriptLines = [];

            foreach ($turns as $i => $turn) {
                $segmentPath = sprintf('%s/turn-%d.mp3', $tmpDir, $i);
                $this->tts->synthesizeToFile(
                    $turn['text'],
                    $segmentPath,
                    new SpeechSynthesisOptions(model: 'tts-1', voice: $turn['voice'], format: 'mp3'),
                );
                $segmentPaths[] = $segmentPath;
                $vttSegments[] = [
                    'speaker' => $turn['speaker'],
                    'text' => $turn['text'],
                    'durationSeconds' => $this->stitcher->probeDurationSeconds($segmentPath),
                ];
                $transcriptLines[] = $turn['speaker'] . ': ' . $turn['text'];
            }

            $mp3Path = $tmpDir . '/podcast.mp3';
            $this->stitcher->concat($segmentPaths, $mp3Path);

            $transcript = implode("\n", $transcriptLines);
            $vtt = $this->vttBuilder->build($vttSegments);

            $mp3File = $this->fileStorage->store((string) file_get_contents($mp3Path), 'podcast.mp3');
            $vttFile = $this->fileStorage->store($vtt, 'podcast.vtt');

            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $mp3File->getUid(),
                'subtitle_file_uid' => $vttFile->getUid(),
                'script_text' => $transcript,
                'metadata' => json_encode([
                    'voices' => [self::VOICE_HOST_A, self::VOICE_HOST_B],
                    'turns' => count($turns),
                    'ttsModel' => 'tts-1',
                ], JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->failArtifact($artifactUid, $jobUid, 'Podcast generation error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return list<array{speaker: string, text: string, voice: string}>
     */
    private function buildDialogue(GenerationContext $ctx): array
    {
        $brief = $ctx->brief;
        $keyPoints = implode("\n- ", $brief->keyPoints);
        $prompt = sprintf(
            "Title: %s\nSummary: %s\nAudience: %s\nKey points:\n- %s\n\n"
            . "Write a lively two-host podcast dialogue (Host A and Host B) that explains this "
            . "content faithfully to the audience. Keep all facts, numbers and labels accurate. "
            . "The length must follow the document scope — short for short documents, longer for rich ones. "
            . "Each turn must be at most 600 characters.",
            $brief->title,
            $brief->summary,
            $brief->audience,
            $keyPoints,
        );

        $options = new ChatOptions(
            temperature: 0.6,
            responseFormat: 'json',
            systemPrompt: sprintf(
                'You are a podcast scriptwriter. Write in language code "%s". Output ONLY valid JSON '
                . 'of the shape {"turns":[{"speaker":"Host A"|"Host B","text":"..."}]}.',
                $brief->language,
            ),
            beUserUid: $ctx->beUser,
            plannedCost: self::SCRIPT_COST,
        );

        $data = $this->completion->completeJson($prompt, $options);
        $rawTurns = is_array($data['turns'] ?? null) ? $data['turns'] : [];

        $turns = [];
        foreach ($rawTurns as $raw) {
            $speaker = (string) ($raw['speaker'] ?? 'Host A');
            $text = trim((string) ($raw['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $turns[] = [
                'speaker' => $speaker,
                'text' => mb_substr($text, 0, 4096),
                'voice' => $speaker === 'Host B' ? self::VOICE_HOST_B : self::VOICE_HOST_A,
            ];
        }

        return $turns;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter PodcastGeneratorTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add Classes/Generator/PodcastGenerator.php Tests/Unit/Generator/PodcastGeneratorTest.php
git commit -s -m "Add PodcastGenerator (two-host dialogue, TTS, stitch, transcript, WebVTT)"
```

---

## Task 5: `SchaubildGenerator` (three variants: html / html_bg / ki_image)

**Files:**
- Create: `Classes/Generator/SchaubildGenerator.php`
- Create: `Resources/Private/Templates/Generated/Schaubild/Nr.html`
- Create: `Resources/Private/Templates/Generated/Schaubild/Neutral.html`
- Test: `Tests/Unit/Generator/SchaubildGeneratorTest.php`

> **Template rendering in unit tests:** `AbstractGenerator::renderTemplate()` uses `StandaloneView` + `GeneralUtility`, which require the TYPO3 bootstrap and are awkward to unit-test. The `SchaubildGenerator` therefore takes the rendered HTML through a small seam: a protected `renderDiagramHtml(GenerationContext): string` that delegates to `renderTemplate()`. The unit test subclasses the generator to override that seam, so the unit test never touches Fluid/`GeneralUtility`. The branded templates are exercised by the optional functional smoke (Task 8) and visually in Plan 6.

- [ ] **Step 1: Write the failing unit test `Tests/Unit/Generator/SchaubildGeneratorTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\SchaubildGenerator;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\ImageCompositorInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Specialized\Image\FalImageService;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\File;

final class SchaubildGeneratorTest extends TestCase
{
    private function context(): GenerationContext
    {
        $document = new SourceDocument('Report', 'text', 'https://example.com/', 0, 'en');
        $brief = new ContentBrief('Report', 'Summary', ['A', 'B'], [['heading' => 'H', 'body' => 'B']], 'All', 'en');

        return new GenerationContext(
            jobRow: ['uid' => 11, 'theme' => 'nr', 'be_user' => 4, 'want_schaubild' => 1],
            document: $document,
            brief: $brief,
            theme: 'nr',
            beUser: 4,
        );
    }

    /** Subclass that bypasses the Fluid/GeneralUtility seam in unit context. */
    private function generator(
        CompletionServiceInterface $completion,
        HtmlToImageRendererInterface $renderer,
        ImageCompositorInterface $compositor,
        FalImageService $fal,
        JobFileStorage $storage,
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
    ): SchaubildGenerator {
        return new class($jobs, $budget, $completion, $renderer, $compositor, $fal, $storage) extends SchaubildGenerator {
            public function __construct(
                JobProcessingRepository $jobs,
                BudgetServiceInterface $budget,
                CompletionServiceInterface $completion,
                HtmlToImageRendererInterface $renderer,
                ImageCompositorInterface $compositor,
                FalImageService $fal,
                JobFileStorage $storage,
            ) {
                parent::__construct($jobs, $budget, new NullLogger(), $completion, $renderer, $compositor, $fal, $storage);
            }

            protected function renderDiagramHtml(GenerationContext $ctx, bool $transparent): string
            {
                return $transparent
                    ? '<html><body style="background:transparent">DIAGRAM</body></html>'
                    : '<html><body>DIAGRAM</body></html>';
            }
        };
    }

    public function testProducesThreeVariantArtifactsWhenBudgetAllows(): void
    {
        $completion = $this->completion('## diagram body');
        $renderer = $this->fakeRenderer();
        $compositor = $this->fakeCompositor();
        $fal = $this->fakeFal();
        [$storage] = $this->fakeStorage();
        $jobs = $this->recordingJobs();

        $generator = $this->generator($completion, $renderer, $compositor, $fal, $storage, $jobs, $this->allowingBudget());

        $result = $generator->generate($this->context());

        self::assertTrue($result);
        // Three artifact rows inserted, one per variant, all type schaubild.
        self::assertSame(
            [['schaubild', 'html'], ['schaubild', 'html_bg'], ['schaubild', 'ki_image']],
            $jobs->inserted,
        );
        // All three variants ended up done, each with source_html stored.
        foreach ($jobs->updates as $update) {
            self::assertSame('done', $update['status']);
            self::assertArrayHasKey('source_html', $update);
            self::assertGreaterThan(0, (int) $update['file_uid']);
        }
        // html_bg used the compositor; ki_image used FAL imageToImage.
        self::assertSame(1, $compositor->overlayCalls);
        self::assertSame('imageToImage', $fal->lastMethod);
    }

    public function testOverBudgetFailsOnlyFalVariantsButHtmlSucceeds(): void
    {
        $completion = $this->completion('body');
        $renderer = $this->fakeRenderer();
        $jobs = $this->recordingJobs();
        $fal = $this->fakeFal();
        [$storage] = $this->fakeStorage();

        $generator = $this->generator($completion, $renderer, $this->fakeCompositor(), $fal, $storage, $jobs, $this->denyingBudget());

        $result = $generator->generate($this->context());

        // html variant has no Specialized call, so it still succeeds (partial success => true).
        self::assertTrue($result);
        self::assertSame('done', $jobs->updates[$jobs->uidForVariant('html')]['status']);
        self::assertSame('failed', $jobs->updates[$jobs->uidForVariant('html_bg')]['status']);
        self::assertSame('failed', $jobs->updates[$jobs->uidForVariant('ki_image')]['status']);
        // No FAL call attempted while over budget.
        self::assertNull($fal->lastMethod);
    }

    public function testSupportsReadsWantSchaubildFlag(): void
    {
        $generator = $this->generator(
            $this->completion('b'), $this->fakeRenderer(), $this->fakeCompositor(), $this->fakeFal(), $this->fakeStorage()[0], $this->recordingJobs(), $this->allowingBudget(),
        );
        self::assertTrue($generator->supports($this->context()));
    }

    private function completion(string $body): CompletionServiceInterface
    {
        return new class($body) implements CompletionServiceInterface {
            public ?ChatOptions $seenOptions = null;

            public function __construct(private readonly string $body) {}

            public function complete(string $p, ?ChatOptions $o = null): \Netresearch\NrLlm\Domain\Model\CompletionResponse { throw new \LogicException('x'); }
            public function completeJson(string $p, ?ChatOptions $o = null): array { throw new \LogicException('x'); }
            public function completeMarkdown(string $p, ?ChatOptions $o = null): string { $this->seenOptions = $o; return $this->body; }
            public function completeFactual(string $p, ?ChatOptions $o = null): \Netresearch\NrLlm\Domain\Model\CompletionResponse { throw new \LogicException('x'); }
            public function completeCreative(string $p, ?ChatOptions $o = null): \Netresearch\NrLlm\Domain\Model\CompletionResponse { throw new \LogicException('x'); }
        };
    }

    private function fakeRenderer(): HtmlToImageRendererInterface
    {
        return new class implements HtmlToImageRendererInterface {
            public int $calls = 0;

            public function render(string $html, int $width, ?int $height, float $deviceScaleFactor = 1.0, bool $transparent = false): string
            {
                $this->calls++;
                $path = sys_get_temp_dir() . '/render_' . bin2hex(random_bytes(4)) . '.png';
                file_put_contents($path, 'PNG');

                return $path;
            }
        };
    }

    private function fakeCompositor(): ImageCompositorInterface
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

    private function fakeFal(): FalImageService
    {
        return new class extends FalImageService {
            public ?string $lastMethod = null;
            public bool $available = true;

            public function __construct() {}

            public function isAvailable(): bool { return $this->available; }

            public function generate(string $prompt, string $model = 'flux-schnell', array $options = []): ImageGenerationResult
            {
                $this->lastMethod = 'generate';

                return $this->result();
            }

            public function imageToImage(string $imageUrl, string $prompt, string $model = 'flux-dev', array $options = []): ImageGenerationResult
            {
                $this->lastMethod = 'imageToImage';

                return $this->result();
            }

            private function result(): ImageGenerationResult
            {
                // base64 set so saveToFile() writes locally without a network download.
                return new ImageGenerationResult(
                    'https://fal.example/out.png',
                    base64_encode('FALPNG'),
                    'prompt',
                    null,
                    'flux-dev',
                    '1024x1024',
                    'fal',
                    null,
                );
            }
        };
    }

    /** @return array{0: JobFileStorage} */
    private function fakeStorage(): array
    {
        $storage = new class extends JobFileStorage {
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

        return [$storage];
    }

    private function recordingJobs(): JobProcessingRepository
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

            public function insertArtifact(int $jobUid, \Netresearch\NrRepurpose\Domain\Enum\ArtifactType $type, string $variant, int $fileUid, \Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus $status, ?string $error = null): int
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
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter SchaubildGeneratorTest`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Generator\SchaubildGenerator" not found`.

- [ ] **Step 3: Write `Classes/Generator/SchaubildGenerator.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\ImageCompositorInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Specialized\Image\FalImageService;
use Psr\Log\LoggerInterface;

/**
 * Produces a branded diagram in three artifact rows for empirical comparison (spec §8):
 *   - html      : LLM-built branded HTML rendered opaque to PNG (labels 100% correct, reference)
 *   - html_bg   : FAL background image + the same HTML rendered transparent + compositor overlay
 *   - ki_image  : opaque html PNG -> data URI -> FAL imageToImage (fully AI-rendered, weakest)
 *
 * The diagram width is 1200px, auto-height. The LLM call (diagram body via CompletionService)
 * is budget-middleware guarded; the two FAL calls (Specialized, not middleware-guarded) are
 * gated manually. A FAL variant that is over budget / unavailable is marked failed; the html
 * variant has no Specialized call so it always proceeds.
 */
class SchaubildGenerator extends AbstractGenerator
{
    private const WIDTH = 1200;
    private const FAL_COST = 0.05;

    public function __construct(
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
        LoggerInterface $logger,
        private readonly CompletionServiceInterface $completion,
        private readonly HtmlToImageRendererInterface $renderer,
        private readonly ImageCompositorInterface $compositor,
        private readonly FalImageService $fal,
        private readonly JobFileStorage $fileStorage,
    ) {
        parent::__construct($jobs, $budget, $logger);
    }

    public function supports(GenerationContext $ctx): bool
    {
        return (bool) ($ctx->jobRow['want_schaubild'] ?? false);
    }

    public function generate(GenerationContext $ctx): bool
    {
        $jobUid = $ctx->jobUid();

        $htmlOpaque = $this->renderDiagramHtml($ctx, false);
        $htmlTransparent = $this->renderDiagramHtml($ctx, true);

        $ok = $this->generateHtmlVariant($ctx, $jobUid, $htmlOpaque);
        $ok = $this->generateHtmlBgVariant($ctx, $jobUid, $htmlTransparent) || $ok;
        $ok = $this->generateKiImageVariant($ctx, $jobUid, $htmlOpaque) || $ok;

        return $ok;
    }

    /** Variant 1 — deterministic Chromium screenshot of the branded HTML. */
    private function generateHtmlVariant(GenerationContext $ctx, int $jobUid, string $html): bool
    {
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Schaubild, 'html', 0, ArtifactStatus::Pending);
        try {
            $pngPath = $this->renderer->render($html, self::WIDTH, null, 2.0, false);
            $file = $this->fileStorage->store((string) file_get_contents($pngPath), 'schaubild-html.png');
            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $file->getUid(),
                'source_html' => $html,
                'metadata' => json_encode(['variant' => 'html', 'width' => self::WIDTH], JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->failArtifact($artifactUid, $jobUid, 'Schaubild html variant error: ' . $e->getMessage());

            return false;
        }
    }

    /** Variant 2 — FAL background, transparent HTML overlay composited on top. */
    private function generateHtmlBgVariant(GenerationContext $ctx, int $jobUid, string $transparentHtml): bool
    {
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Schaubild, 'html_bg', 0, ArtifactStatus::Pending);
        if (!$this->specializedAllowed($ctx, self::FAL_COST, $this->fal->isAvailable())) {
            $this->failArtifact($artifactUid, $jobUid, 'AI budget exhausted or image service unavailable');

            return false;
        }
        try {
            $tmpDir = $this->makeTempDir();
            $background = $this->fal->generate($this->backgroundPrompt($ctx), 'flux-schnell', [
                'image_size' => 'landscape_16_9',
                'num_inference_steps' => 4,
            ]);
            $bgPath = $tmpDir . '/bg.png';
            $background->saveToFile($bgPath);

            $fgPath = $this->renderer->render($transparentHtml, self::WIDTH, null, 2.0, true);
            $outPath = $tmpDir . '/composited.png';
            $this->compositor->overlay($bgPath, $fgPath, $outPath);

            $file = $this->fileStorage->store((string) file_get_contents($outPath), 'schaubild-html-bg.png');
            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $file->getUid(),
                'source_html' => $transparentHtml,
                'metadata' => json_encode(['variant' => 'html_bg', 'bgModel' => 'flux-schnell'], JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->failArtifact($artifactUid, $jobUid, 'Schaubild html_bg variant error: ' . $e->getMessage());

            return false;
        }
    }

    /** Variant 3 — opaque html PNG re-rendered fully by FAL imageToImage. */
    private function generateKiImageVariant(GenerationContext $ctx, int $jobUid, string $opaqueHtml): bool
    {
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Schaubild, 'ki_image', 0, ArtifactStatus::Pending);
        if (!$this->specializedAllowed($ctx, self::FAL_COST, $this->fal->isAvailable())) {
            $this->failArtifact($artifactUid, $jobUid, 'AI budget exhausted or image service unavailable');

            return false;
        }
        try {
            $structurePng = $this->renderer->render($opaqueHtml, self::WIDTH, null, 2.0, false);
            $dataUri = 'data:image/png;base64,' . base64_encode((string) file_get_contents($structurePng));
            $result = $this->fal->imageToImage(
                $dataUri,
                $this->kiImagePrompt($ctx),
                'flux-dev',
                ['strength' => 0.6],
            );
            $tmpDir = $this->makeTempDir();
            $outPath = $tmpDir . '/ki.png';
            $result->saveToFile($outPath);

            $file = $this->fileStorage->store((string) file_get_contents($outPath), 'schaubild-ki.png');
            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $file->getUid(),
                'source_html' => $opaqueHtml,
                'metadata' => json_encode(['variant' => 'ki_image', 'model' => 'flux-dev', 'strength' => 0.6], JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->failArtifact($artifactUid, $jobUid, 'Schaubild ki_image variant error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Build the branded diagram HTML: ask the LLM for the diagram BODY (factual content),
     * then wrap it in the theme template (StandaloneView). Seam isolated for unit testing.
     */
    protected function renderDiagramHtml(GenerationContext $ctx, bool $transparent): string
    {
        $brief = $ctx->brief;
        $keyPoints = implode("\n- ", $brief->keyPoints);
        $prompt = sprintf(
            "Title: %s\nSummary: %s\nKey points:\n- %s\n\n"
            . "Produce the inner HTML body of an infographic/diagram that visualises this content. "
            . "Use semantic, self-contained HTML with inline classes only (no <html>/<head>). "
            . "Keep every label, number and term exactly as given. Write text in language code \"%s\".",
            $brief->title,
            $brief->summary,
            $keyPoints,
            $brief->language,
        );
        $options = new ChatOptions(
            temperature: 0.3,
            systemPrompt: 'You are an information designer. Output an HTML fragment only.',
            beUserUid: $ctx->beUser,
            plannedCost: 0.03,
        );
        $bodyHtml = $this->completion->completeMarkdown($prompt, $options);

        return $this->renderTemplate('Schaubild', $ctx->theme, [
            'title' => $brief->title,
            'bodyHtml' => $bodyHtml,
            'transparent' => $transparent,
        ]);
    }

    private function backgroundPrompt(GenerationContext $ctx): string
    {
        return sprintf(
            'Abstract, subtle background for an infographic about "%s". Soft gradients, no text, '
            . 'leave the center calm for an overlay. Theme: %s.',
            $ctx->brief->title,
            $ctx->theme === 'nr' ? 'teal and orange corporate' : 'neutral light',
        );
    }

    private function kiImagePrompt(GenerationContext $ctx): string
    {
        return sprintf(
            'Re-render this diagram structure as a polished infographic about "%s", keeping the same '
            . 'layout and proportions.',
            $ctx->brief->title,
        );
    }
}
```

> **Note on the partial-success contract:** `generate()` returns `true` if **any** variant succeeded, matching the spec §12 "a failed artifact does not end the siblings" at the variant level. Each variant row carries its own `status`. The orchestrator (Plan 3) treats a generator returning `false` as the trigger for `partially_done`; since the `html` variant has no Specialized dependency it reliably succeeds, so a budget-starved run still yields one usable diagram and `generate()` returns `true`.

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter SchaubildGeneratorTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Write the NR theme template `Resources/Private/Templates/Generated/Schaubild/Nr.html`**

```html
<f:spaceless>
<!DOCTYPE html>
<html lang="{f:if(condition: brief, then: brief.language, else: 'en')}">
<head>
    <meta charset="utf-8">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&family=Open+Sans:wght@400;600;700&display=swap');
        :root {
            --nr-primary: #2F99A4;
            --nr-accent: #FF4D00;
            --nr-text: #585961;
            --font-headline: 'Raleway', 'Helvetica Neue', Arial, sans-serif;
            --font-body: 'Open Sans', -apple-system, 'Segoe UI', Roboto, sans-serif;
        }
        html, body {
            margin: 0;
            padding: 0;
            <f:if condition="{transparent}">
                <f:then>background: transparent;</f:then>
                <f:else>background: #ffffff;</f:else>
            </f:if>
        }
        .schaubild {
            width: 1200px;
            box-sizing: border-box;
            padding: 64px;
            font-family: var(--font-body);
            color: var(--nr-text);
        }
        .schaubild__header {
            border-bottom: 4px solid var(--nr-accent);
            padding-bottom: 24px;
            margin-bottom: 32px;
        }
        .schaubild__title {
            font-family: var(--font-headline);
            font-weight: 700;
            font-size: 48px;
            line-height: 1.2;
            color: var(--nr-primary);
            margin: 0;
        }
        .schaubild__body {
            font-size: 20px;
            line-height: 1.6;
        }
        .schaubild__body h2,
        .schaubild__body h3 {
            font-family: var(--font-headline);
            color: var(--nr-primary);
        }
        .schaubild__footer {
            margin-top: 40px;
            font-size: 14px;
            color: var(--nr-text);
        }
        .schaubild__footer a { color: var(--nr-primary); text-decoration: none; }
    </style>
</head>
<body>
    <div class="schaubild">
        <header class="schaubild__header">
            <h1 class="schaubild__title">{title}</h1>
        </header>
        <main class="schaubild__body">
            <f:format.raw>{bodyHtml}</f:format.raw>
        </main>
        <footer class="schaubild__footer">
            <a href="https://www.netresearch.de/">Netresearch DTT GmbH</a>
        </footer>
    </div>
</body>
</html>
</f:spaceless>
```

- [ ] **Step 6: Write the neutral theme template `Resources/Private/Templates/Generated/Schaubild/Neutral.html`**

```html
<f:spaceless>
<!DOCTYPE html>
<html lang="{f:if(condition: brief, then: brief.language, else: 'en')}">
<head>
    <meta charset="utf-8">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        :root {
            --fg: #1f2933;
            --accent: #3b6ef5;
            --font: 'Inter', -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
        }
        html, body {
            margin: 0;
            padding: 0;
            <f:if condition="{transparent}">
                <f:then>background: transparent;</f:then>
                <f:else>background: #ffffff;</f:else>
            </f:if>
        }
        .schaubild {
            width: 1200px;
            box-sizing: border-box;
            padding: 64px;
            font-family: var(--font);
            color: var(--fg);
        }
        .schaubild__header {
            border-bottom: 3px solid var(--accent);
            padding-bottom: 24px;
            margin-bottom: 32px;
        }
        .schaubild__title {
            font-weight: 700;
            font-size: 48px;
            line-height: 1.2;
            margin: 0;
        }
        .schaubild__body { font-size: 20px; line-height: 1.6; }
        .schaubild__body h2, .schaubild__body h3 { color: var(--accent); }
    </style>
</head>
<body>
    <div class="schaubild">
        <header class="schaubild__header">
            <h1 class="schaubild__title">{title}</h1>
        </header>
        <main class="schaubild__body">
            <f:format.raw>{bodyHtml}</f:format.raw>
        </main>
    </div>
</body>
</html>
</f:spaceless>
```

- [ ] **Step 7: Commit**

```bash
git add Classes/Generator/SchaubildGenerator.php Resources/Private/Templates/Generated/Schaubild Tests/Unit/Generator/SchaubildGeneratorTest.php
git commit -s -m "Add SchaubildGenerator with three variants and branded diagram templates"
```

---

## Task 6: `StoryGenerator` (9:16 1080x1920 PNG, optional KI background)

**Files:**
- Create: `Classes/Generator/StoryGenerator.php`
- Create: `Resources/Private/Templates/Generated/Story/Nr.html`
- Create: `Resources/Private/Templates/Generated/Story/Neutral.html`
- Test: `Tests/Unit/Generator/StoryGeneratorTest.php`

- [ ] **Step 1: Write the failing unit test `Tests/Unit/Generator/StoryGeneratorTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\StoryGenerator;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\ImageCompositorInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Specialized\Image\FalImageService;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\File;

final class StoryGeneratorTest extends TestCase
{
    private function context(bool $wantStory = true): GenerationContext
    {
        $document = new SourceDocument('Report', 'text', 'https://example.com/', 0, 'en');
        $brief = new ContentBrief('Report', 'A crisp summary.', ['Point'], [], 'All', 'en');

        return new GenerationContext(
            jobRow: ['uid' => 21, 'theme' => 'nr', 'be_user' => 5, 'want_story' => $wantStory ? 1 : 0],
            document: $document,
            brief: $brief,
            theme: 'nr',
            beUser: 5,
        );
    }

    private function generator(
        HtmlToImageRendererInterface $renderer,
        FalImageService $fal,
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
    ): StoryGenerator {
        return new class($jobs, $budget, $renderer, $fal) extends StoryGenerator {
            public function __construct(JobProcessingRepository $jobs, BudgetServiceInterface $budget, HtmlToImageRendererInterface $renderer, FalImageService $fal)
            {
                parent::__construct(
                    $jobs,
                    $budget,
                    new NullLogger(),
                    new class implements CompletionServiceInterface {
                        public function complete(string $p, ?ChatOptions $o = null): \Netresearch\NrLlm\Domain\Model\CompletionResponse { throw new \LogicException('x'); }
                        public function completeJson(string $p, ?ChatOptions $o = null): array { return ['headline' => 'Big News', 'subline' => 'Details inside']; }
                        public function completeMarkdown(string $p, ?ChatOptions $o = null): string { throw new \LogicException('x'); }
                        public function completeFactual(string $p, ?ChatOptions $o = null): \Netresearch\NrLlm\Domain\Model\CompletionResponse { throw new \LogicException('x'); }
                        public function completeCreative(string $p, ?ChatOptions $o = null): \Netresearch\NrLlm\Domain\Model\CompletionResponse { throw new \LogicException('x'); }
                    },
                    $renderer,
                    new class implements ImageCompositorInterface {
                        public function overlay(string $b, string $f, string $o): string { file_put_contents($o, 'C'); return $o; }
                    },
                    $fal,
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
        $renderer = $this->fakeRenderer();
        $jobs = $this->recordingJobs();

        $generator = $this->generator($renderer, $this->fakeFal(), $jobs, $this->allowingBudget());

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
        $generator = $this->generator($this->fakeRenderer(), $this->fakeFal(), $this->recordingJobs(), $this->allowingBudget());
        self::assertTrue($generator->supports($this->context(true)));
        self::assertFalse($generator->supports($this->context(false)));
    }

    private function fakeRenderer(): HtmlToImageRendererInterface
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

    private function fakeFal(): FalImageService
    {
        return new class extends FalImageService {
            public bool $available = false; // default: no KI background

            public function __construct() {}

            public function isAvailable(): bool { return $this->available; }

            public function generate(string $prompt, string $model = 'flux-schnell', array $options = []): ImageGenerationResult
            {
                return new ImageGenerationResult('https://x/o.png', base64_encode('P'), 'p', null, 'flux-schnell', '1024x1792', 'fal', null);
            }
        };
    }

    private function recordingJobs(): JobProcessingRepository
    {
        return new class extends JobProcessingRepository {
            /** @var list<array{0: string, 1: string}> */
            public array $inserted = [];
            /** @var array<int, array<string, mixed>> */
            public array $updates = [];

            public function __construct() {}

            public function insertArtifact(int $jobUid, \Netresearch\NrRepurpose\Domain\Enum\ArtifactType $type, string $variant, int $fileUid, \Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus $status, ?string $error = null): int
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
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter StoryGeneratorTest`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Generator\StoryGenerator" not found`.

- [ ] **Step 3: Write `Classes/Generator/StoryGenerator.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\ImageCompositorInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Specialized\Image\FalImageService;
use Psr\Log\LoggerInterface;

/**
 * Produces one 9:16 Instagram story (1080x1920 PNG, spec §10). The LLM condenses the brief
 * into a headline + subline (budget-middleware guarded CompletionService); the branded 9:16
 * template is rendered to PNG. Optionally (when FAL is available and within budget) a KI
 * background is generated and the transparent text layer composited over it (like Schaubild
 * variant 2). The KI background is best-effort: if it is over budget / unavailable, the story
 * falls back to the opaque-render PNG and still succeeds.
 */
class StoryGenerator extends AbstractGenerator
{
    private const WIDTH = 1080;
    private const HEIGHT = 1920;
    private const FAL_COST = 0.05;

    public function __construct(
        JobProcessingRepository $jobs,
        BudgetServiceInterface $budget,
        LoggerInterface $logger,
        private readonly CompletionServiceInterface $completion,
        private readonly HtmlToImageRendererInterface $renderer,
        private readonly ImageCompositorInterface $compositor,
        private readonly FalImageService $fal,
        private readonly JobFileStorage $fileStorage,
    ) {
        parent::__construct($jobs, $budget, $logger);
    }

    public function supports(GenerationContext $ctx): bool
    {
        return (bool) ($ctx->jobRow['want_story'] ?? false);
    }

    public function generate(GenerationContext $ctx): bool
    {
        $jobUid = $ctx->jobUid();
        $artifactUid = $this->jobs->insertArtifact($jobUid, ArtifactType::Story, 'default', 0, ArtifactStatus::Pending);

        try {
            $useKiBackground = $this->specializedAllowed($ctx, self::FAL_COST, $this->fal->isAvailable());

            if ($useKiBackground) {
                $html = $this->renderStoryHtml($ctx, true);
                $pngPath = $this->composeWithKiBackground($ctx, $html);
                $metadata = ['width' => self::WIDTH, 'height' => self::HEIGHT, 'background' => 'ki'];
            } else {
                $html = $this->renderStoryHtml($ctx, false);
                $pngPath = $this->renderer->render($html, self::WIDTH, self::HEIGHT, 1.0, false);
                $metadata = ['width' => self::WIDTH, 'height' => self::HEIGHT, 'background' => 'flat'];
            }

            $file = $this->fileStorage->store((string) file_get_contents($pngPath), 'story.png');
            $this->jobs->updateArtifact($artifactUid, [
                'file_uid' => $file->getUid(),
                'source_html' => $html,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'status' => ArtifactStatus::Done->value,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->failArtifact($artifactUid, $jobUid, 'Story generation error: ' . $e->getMessage());

            return false;
        }
    }

    private function composeWithKiBackground(GenerationContext $ctx, string $transparentHtml): string
    {
        $tmpDir = $this->makeTempDir();
        $background = $this->fal->generate($this->backgroundPrompt($ctx), 'flux-schnell', [
            'image_size' => 'portrait_16_9',
            'num_inference_steps' => 4,
        ]);
        $bgPath = $tmpDir . '/bg.png';
        $background->saveToFile($bgPath);

        $fgPath = $this->renderer->render($transparentHtml, self::WIDTH, self::HEIGHT, 1.0, true);
        $outPath = $tmpDir . '/story.png';
        $this->compositor->overlay($bgPath, $fgPath, $outPath);

        return $outPath;
    }

    /** Build the branded 9:16 HTML; seam isolated for unit testing. */
    protected function renderStoryHtml(GenerationContext $ctx, bool $transparent): string
    {
        $brief = $ctx->brief;
        $prompt = sprintf(
            "Title: %s\nSummary: %s\n\nCondense this into one punchy Instagram-story headline (<=60 chars) "
            . "and a short subline (<=110 chars). Write in language code \"%s\". "
            . 'Output ONLY JSON {"headline":"...","subline":"..."}.',
            $brief->title,
            $brief->summary,
            $brief->language,
        );
        $options = new ChatOptions(
            temperature: 0.5,
            responseFormat: 'json',
            systemPrompt: 'You are a social-media copywriter. Output ONLY valid JSON.',
            beUserUid: $ctx->beUser,
            plannedCost: 0.01,
        );
        $data = $this->completion->completeJson($prompt, $options);

        return $this->renderTemplate('Story', $ctx->theme, [
            'headline' => (string) ($data['headline'] ?? $brief->title),
            'subline' => (string) ($data['subline'] ?? $brief->summary),
            'transparent' => $transparent,
        ]);
    }

    private function backgroundPrompt(GenerationContext $ctx): string
    {
        return sprintf(
            'Vertical 9:16 abstract background for an Instagram story about "%s". No text, soft '
            . 'gradients, leave space top and bottom for overlaid copy. Theme: %s.',
            $ctx->brief->title,
            $ctx->theme === 'nr' ? 'teal and orange corporate' : 'neutral light',
        );
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter StoryGeneratorTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Write the NR story template `Resources/Private/Templates/Generated/Story/Nr.html`**

```html
<f:spaceless>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Raleway:wght@600;700&family=Open+Sans:wght@400;600&display=swap');
        html, body {
            margin: 0;
            padding: 0;
            width: 1080px;
            height: 1920px;
            <f:if condition="{transparent}">
                <f:then>background: transparent;</f:then>
                <f:else>background: linear-gradient(160deg, #2F99A4 0%, #0f3f44 100%);</f:else>
            </f:if>
        }
        .story {
            box-sizing: border-box;
            width: 1080px;
            height: 1920px;
            padding: 120px 96px;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            color: #ffffff;
            font-family: 'Open Sans', -apple-system, 'Segoe UI', Roboto, sans-serif;
        }
        .story__accent {
            width: 120px;
            height: 12px;
            background: #FF4D00;
            margin-bottom: 40px;
        }
        .story__headline {
            font-family: 'Raleway', 'Helvetica Neue', Arial, sans-serif;
            font-weight: 700;
            font-size: 96px;
            line-height: 1.1;
            margin: 0 0 32px;
        }
        .story__subline {
            font-size: 40px;
            line-height: 1.4;
            font-weight: 400;
            margin: 0;
        }
        .story__brand {
            margin-top: 64px;
            font-family: 'Raleway', sans-serif;
            font-weight: 600;
            font-size: 28px;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>
    <div class="story">
        <div class="story__accent"></div>
        <h1 class="story__headline">{headline}</h1>
        <p class="story__subline">{subline}</p>
        <div class="story__brand">Netresearch DTT GmbH</div>
    </div>
</body>
</html>
</f:spaceless>
```

- [ ] **Step 6: Write the neutral story template `Resources/Private/Templates/Generated/Story/Neutral.html`**

```html
<f:spaceless>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        html, body {
            margin: 0;
            padding: 0;
            width: 1080px;
            height: 1920px;
            <f:if condition="{transparent}">
                <f:then>background: transparent;</f:then>
                <f:else>background: linear-gradient(160deg, #1f2933 0%, #3b4654 100%);</f:else>
            </f:if>
        }
        .story {
            box-sizing: border-box;
            width: 1080px;
            height: 1920px;
            padding: 120px 96px;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            color: #ffffff;
            font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
        }
        .story__accent { width: 120px; height: 12px; background: #3b6ef5; margin-bottom: 40px; }
        .story__headline { font-weight: 700; font-size: 96px; line-height: 1.1; margin: 0 0 32px; }
        .story__subline { font-size: 40px; line-height: 1.4; font-weight: 400; margin: 0; }
    </style>
</head>
<body>
    <div class="story">
        <div class="story__accent"></div>
        <h1 class="story__headline">{headline}</h1>
        <p class="story__subline">{subline}</p>
    </div>
</body>
</html>
</f:spaceless>
```

- [ ] **Step 7: Commit**

```bash
git add Classes/Generator/StoryGenerator.php Resources/Private/Templates/Generated/Story Tests/Unit/Generator/StoryGeneratorTest.php
git commit -s -m "Add StoryGenerator (9:16 story PNG with optional KI background) and templates"
```

---

## Task 7: Register generators, capability perm options, disable stub from the tagged set

**Files:**
- Modify: `ext_localconf.php`
- Modify: `Configuration/Services.yaml`
- Test: `Tests/Functional/Generator/GeneratorRegistrationTest.php`

> **Tagging model:** Plan 3 changes `GenerationOrchestrator` to consume `iterable<ArtifactGeneratorInterface>` injected via a `nr_repurpose.artifact_generator` tagged iterator (the canonical TYPO3 v14 tagged-iterator pattern). This task tags the three real generators and removes the tag from `StubArtifactGenerator`. If Plan 3 wired the orchestrator differently (e.g. `instanceof` autoconfiguration on the interface), align Step 2 to that mechanism — but the real generators MUST be in the orchestrator's set and the stub MUST NOT be.

- [ ] **Step 1: Write the failing functional test `Tests/Functional/Generator/GeneratorRegistrationTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Generator;

use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Generator\PodcastGenerator;
use Netresearch\NrRepurpose\Generator\SchaubildGenerator;
use Netresearch\NrRepurpose\Generator\StoryGenerator;
use Netresearch\NrRepurpose\Generator\StubArtifactGenerator;
use Netresearch\NrRepurpose\Service\GenerationOrchestrator;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class GeneratorRegistrationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-repurpose'];

    public function testThreeRealGeneratorsAreAutowirable(): void
    {
        self::assertInstanceOf(ArtifactGeneratorInterface::class, $this->get(PodcastGenerator::class));
        self::assertInstanceOf(ArtifactGeneratorInterface::class, $this->get(SchaubildGenerator::class));
        self::assertInstanceOf(ArtifactGeneratorInterface::class, $this->get(StoryGenerator::class));
    }

    public function testOrchestratorReceivesTheThreeRealGeneratorsButNotTheStub(): void
    {
        $orchestrator = $this->get(GenerationOrchestrator::class);

        $ref = new \ReflectionClass($orchestrator);
        $prop = $ref->getProperty('generators');
        $prop->setAccessible(true);
        /** @var iterable<ArtifactGeneratorInterface> $generators */
        $generators = $prop->getValue($orchestrator);

        $classes = [];
        foreach ($generators as $generator) {
            $classes[] = $generator::class;
        }

        self::assertContains(PodcastGenerator::class, $classes);
        self::assertContains(SchaubildGenerator::class, $classes);
        self::assertContains(StoryGenerator::class, $classes);
        self::assertNotContains(StubArtifactGenerator::class, $classes);
    }

    public function testCapabilityPermOptionsAreRegistered(): void
    {
        // ext_localconf.php registers the perm options at extension load.
        $options = $GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['nrrepurpose'] ?? null;

        self::assertIsArray($options);
        self::assertArrayHasKey('items', $options);
        self::assertArrayHasKey('generate_audio', $options['items']);
        self::assertArrayHasKey('generate_vision', $options['items']);
    }
}
```

> The `generators` property name + storage shape come from Plan 1's `GenerationOrchestrator` (`private array $generators;` materialised from the injected iterable). If Plan 3 renamed it, adjust the reflection target accordingly.

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter GeneratorRegistrationTest"`
Expected: FAIL — either the real generators are not in the orchestrator set (stub still tagged) or `customPermOptions['nrrepurpose']` is not registered.

- [ ] **Step 3: Edit `Configuration/Services.yaml` — tag the three real generators, drop the stub**

Replace the autoconfigure-only block (Plan 1 left the generators untagged or tagged the stub) so ONLY the three real generators carry the `nr_repurpose.artifact_generator` tag. Append these explicit definitions after the `Netresearch\NrRepurpose\:` resource block:

```yaml
  # Real artifact generators are the orchestrator's tagged set (Plan 5).
  Netresearch\NrRepurpose\Generator\PodcastGenerator:
    tags: ['nr_repurpose.artifact_generator']
  Netresearch\NrRepurpose\Generator\SchaubildGenerator:
    tags: ['nr_repurpose.artifact_generator']
  Netresearch\NrRepurpose\Generator\StoryGenerator:
    tags: ['nr_repurpose.artifact_generator']

  # The walking-skeleton stub stays loadable for tests but is NO LONGER part of the
  # orchestrator's generator set (its tag is removed).
  Netresearch\NrRepurpose\Generator\StubArtifactGenerator:
    tags: []

  # The orchestrator consumes all tagged generators as a tagged iterator.
  Netresearch\NrRepurpose\Service\GenerationOrchestrator:
    arguments:
      $generators: !tagged_iterator nr_repurpose.artifact_generator

  # Plan-4 render interfaces -> implementations.
  Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface:
    alias: Netresearch\NrRepurpose\Rendering\PlaywrightHtmlToImageRenderer
  Netresearch\NrRepurpose\Rendering\ImageCompositorInterface:
    alias: Netresearch\NrRepurpose\Rendering\GdImageCompositor
  Netresearch\NrRepurpose\Rendering\AudioStitcherInterface:
    alias: Netresearch\NrRepurpose\Rendering\FfmpegAudioStitcher
```

> If `autoconfigure: true` plus an interface-instanceof tag already tags every `ArtifactGeneratorInterface` implementation (Plan 3 may have set `_instanceof`), then instead exclude the stub explicitly:
> ```yaml
>   Netresearch\NrRepurpose\Generator\StubArtifactGenerator:
>     autoconfigure: false
> ```
> and keep the `_instanceof` block for the interface. Pick the mechanism that matches Plan 3; the test in Step 1 is the gate. The Plan-4 render-interface aliases may already exist from Plan 4 — keep a single definition, do not duplicate.

- [ ] **Step 4: Edit `ext_localconf.php` — register capability perm options**

Replace the Plan-1 placeholder body with the real registration. There is no IMAGE/SPEECH capability in nr-llm, so audio gating maps to `ModelCapability::AUDIO` and image/vision gating to `ModelCapability::VISION` (grounding [20]).

```php
<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Netresearch\NrLlm\Domain\Enum\ModelCapability;

// Backend capability permission options for nr_repurpose runs. nr-llm has no dedicated
// IMAGE/SPEECH capability, so audio generation gates on AUDIO and image/vision on VISION.
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['nrrepurpose'] = [
    'header' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.header',
    'items' => [
        'generate_audio' => [
            'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.generate_audio',
            'actions-volume-up',
            'Generate podcast audio (maps to nr_llm capability ' . ModelCapability::AUDIO->value . ')',
        ],
        'generate_vision' => [
            'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.generate_vision',
            'actions-image',
            'Generate AI imagery (maps to nr_llm capability ' . ModelCapability::VISION->value . ')',
        ],
    ],
];
```

> `customPermOptions` items are `[label, iconIdentifier, description]` triples; the icon identifiers `actions-volume-up` / `actions-image` are Core icons. Add the two XLF keys (`perm.header`, `perm.generate_audio`, `perm.generate_vision`) to `Resources/Private/Language/locallang.xlf` alongside the existing labels.

- [ ] **Step 5: Add the XLF labels to `Resources/Private/Language/locallang.xlf`**

Insert these `<trans-unit>` entries inside the existing `<body>` (2-space indentation, TYPO3 v14 XLIFF convention):

```xml
        <trans-unit id="perm.header">
          <source>Content Repurpose</source>
        </trans-unit>
        <trans-unit id="perm.generate_audio">
          <source>Generate podcast audio</source>
        </trans-unit>
        <trans-unit id="perm.generate_vision">
          <source>Generate AI imagery</source>
        </trans-unit>
```

- [ ] **Step 6: Flush DI cache and run the test, verify it passes**

Run:
```bash
cd /home/sme/p/nr-repurpose/main
ddev exec ".Build/bin/typo3 cache:flush"
ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter GeneratorRegistrationTest"
```
Expected: PASS (3 tests). The functional harness rebuilds the container from the loaded extension's `Services.yaml`, so the tagged-iterator wiring and perm options are exercised against real DI.

- [ ] **Step 7: Run the full unit + functional suites, verify all green**

Run:
```bash
cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml
cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml"
```
Expected: all unit tests PASS (WebVtt + 3 generators + Plan 1–4 suites); all functional tests PASS (updateArtifact, registration + Plan 1–4 suites).

- [ ] **Step 8: Commit**

```bash
git add Configuration/Services.yaml ext_localconf.php Resources/Private/Language/locallang.xlf Tests/Functional/Generator/GeneratorRegistrationTest.php
git commit -s -m "Register real generators as tagged set, add capability perm options, drop stub tag"
```

---

## Task 8 (OPTIONAL): env-gated functional smoke per generator

> **Skip without API keys.** These tests make ONE real nr-llm call each and need a real Chromium/ffmpeg (present in the DDEV image from Plan 1 Task 2). They are skipped automatically when the relevant API key env var is absent, so CI without secrets stays green. Run them manually inside `ddev exec` after setting `OPENAI_API_KEY` / `FAL_API_KEY` in `.ddev/.env`.

**Files:**
- Create: `Tests/Functional/Generator/Smoke/PodcastGeneratorSmokeTest.php`
- Create: `Tests/Functional/Generator/Smoke/SchaubildGeneratorSmokeTest.php`
- Create: `Tests/Functional/Generator/Smoke/StoryGeneratorSmokeTest.php`

- [ ] **Step 1: Write `Tests/Functional/Generator/Smoke/SchaubildGeneratorSmokeTest.php`** (representative; the other two follow the same shape)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Generator\Smoke;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\SchaubildGenerator;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SchaubildGeneratorSmokeTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-repurpose'];

    protected function setUp(): void
    {
        if (getenv('OPENAI_API_KEY') === false || getenv('OPENAI_API_KEY') === '') {
            self::markTestSkipped('OPENAI_API_KEY not set — skipping real-call smoke test.');
        }
        parent::setUp();
    }

    public function testRealSchaubildHtmlVariantProducesArtifactRow(): void
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid' => 0, 'source_type' => 'url', 'source_value' => 'https://example.com/',
            'theme' => 'nr', 'want_podcast' => 0, 'want_schaubild' => 1, 'want_story' => 0,
            'status' => 'generating', 'be_user' => 0,
        ]);
        $jobUid = (int) $conn->lastInsertId();

        $document = new SourceDocument('Demo', 'Revenue grew 12 percent year over year.', 'https://example.com/', 0, 'en');
        $brief = new ContentBrief('Demo', 'Revenue grew 12 percent.', ['Revenue +12%'], [], 'Investors', 'en');
        $ctx = new GenerationContext(
            jobRow: ['uid' => $jobUid, 'theme' => 'nr', 'be_user' => 0, 'want_schaubild' => 1],
            document: $document,
            brief: $brief,
            theme: 'nr',
            beUser: 0,
        );

        $generator = $this->get(SchaubildGenerator::class);
        $generator->generate($ctx);

        $artifactConn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact');
        $htmlVariant = $artifactConn->select(
            ['*'],
            'tx_nrrepurpose_domain_model_artifact',
            ['job' => $jobUid, 'variant' => 'html'],
        )->fetchAssociative();

        self::assertIsArray($htmlVariant);
        self::assertSame('done', $htmlVariant['status']);
        self::assertGreaterThan(0, (int) $htmlVariant['file_uid']);
        self::assertStringContainsString('schaubild', $htmlVariant['source_html']);
    }
}
```

> `be_user => 0` makes `BudgetService::check()` return `allowed()` unconditionally (uid<=0 short-circuit, grounding [16]), so the smoke run is not blocked by budget records. The `PodcastGeneratorSmokeTest` (needs `OPENAI_API_KEY`) and `StoryGeneratorSmokeTest` (needs `OPENAI_API_KEY`; only exercises the flat-background path unless `FAL_API_KEY` is also set) mirror this structure: seed a job, build a `GenerationContext`, call `generate()`, assert the artifact row is `done` with a `file_uid`. Each guards its own required env var in `setUp()`.

- [ ] **Step 2: Run (only with keys present)**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter Smoke"`
Expected (no keys): all smoke tests reported as Skipped. Expected (keys set): PASS, with a real PNG stored in FAL `repurpose/`.

- [ ] **Step 3: Commit**

```bash
git add Tests/Functional/Generator/Smoke
git commit -s -m "Add optional env-gated real-call smoke tests for the three generators"
```

---

## Self-Review

### Spec coverage (this slice)

- **§8 Schaubild — three variants:** `SchaubildGenerator` produces exactly three `tx_nrrepurpose_domain_model_artifact` rows with `type=schaubild` and `variant` ∈ {`html`, `html_bg`, `ki_image`}. `html` = opaque Chromium render of the branded HTML (reference, labels exact); `html_bg` = FAL background + transparent render + `ImageCompositorInterface::overlay`; `ki_image` = opaque render → data URI → `FalImageService::imageToImage` (the spec's "weakest, for comparison" variant). Covered. ✔
- **§9 Podcast:** two-host dialogue from `CompletionService` (length follows document scope — turn count derived from key points + sections, no fixed target); per-turn `TextToSpeechService::synthesizeToFile` alternating `nova`/`onyx`, format mp3; `AudioStitcherInterface::concat` to one mp3 in synthesis order; speaker-tagged transcript in `script_text`; WebVTT cue times from `AudioStitcherInterface::probeDurationSeconds` per segment; mp3 + vtt stored via `JobFileStorage`; artifact `type=podcast` carries `file_uid` + `subtitle_file_uid` + `script_text`. Covered. ✔
- **§10 Story:** `StoryGenerator` renders branded 9:16 HTML to a 1080×1920 opaque PNG; optional KI background via FAL + compositor when available and in budget; artifact `type=story`. Covered. ✔
- **§13 themes:** `Resources/Private/Templates/Generated/{Schaubild,Story}/{Nr,Neutral}.html`; NR theme uses #2F99A4 / #FF4D00 / #585961 and Raleway/Open Sans; transparent variants emit `html,body{background:transparent}` (driven by the `{transparent}` Fluid condition). Covered. ✔
- **Persistence + budget/capability:** `JobProcessingRepository::updateArtifact(int, array)` added (whitelisted columns, contracts-doc signature); capability perm options registered in `ext_localconf.php` mapped to `ModelCapability::AUDIO`/`VISION`; three generators tagged `nr_repurpose.artifact_generator`, stub untagged. Covered. ✔
- NOT in scope (correctly deferred): ingestion (Plan 2), understanding + orchestrator evolution (Plan 3), render interface implementations (Plan 4), BE result-view polish (Plan 6).

### Type-consistency vs the contracts doc

- `ArtifactGeneratorInterface` — all three generators implement the **FINAL** `GenerationContext` signature: `supports(GenerationContext $ctx): bool` and `generate(GenerationContext $ctx): bool` (never the Plan-1 array signature). `supports()` reads `$ctx->jobRow['want_podcast'|'want_schaubild'|'want_story']`. ✔
- `GenerationContext` — consumed read-only: `$ctx->jobUid()`, `$ctx->beUser`, `$ctx->theme`, `$ctx->brief`, `$ctx->document`, `$ctx->jobRow`. No mutation. ✔
- `ContentBrief` — read via `title`, `summary`, `keyPoints` (`list<string>`), `sections` (`list<array{heading,body}>`), `audience`, `language`. ✔
- `SourceDocument` — present on the context; not directly needed by the generators (the brief is the generation input), only passed through; matches Plan 2 shape. ✔
- `JobProcessingRepository::updateArtifact(int $artifactUid, array $fields): void` — verbatim the contracts-doc Plan-5 signature; sets `subtitle_file_uid` / `source_html` / `script_text` / `metadata` / `status` (+ `file_uid`, `variant`, `error_message`). ✔
- `JobFileStorage::store(string $content, string $filename): File` — used unchanged for mp3, png, vtt (Plan 1). ✔
- Plan-4 render interfaces — `HtmlToImageRendererInterface::render(string,int,?int,float,bool): string`, `ImageCompositorInterface::overlay(string,string,string): string`, `AudioStitcherInterface::concat(array,string): string` + `probeDurationSeconds(string): float` — called exactly per the contract. ✔
- nr-llm APIs — `CompletionServiceInterface::completeJson`/`completeMarkdown` (public interface), `TextToSpeechService::synthesizeToFile` + `isAvailable` (public concrete), `FalImageService::generate`/`imageToImage` + `isAvailable` (public concrete), `BudgetServiceInterface::check` (public interface), `SpeechSynthesisOptions(model,voice,format)`, `ChatOptions(temperature,responseFormat,systemPrompt,beUserUid,plannedCost)`, `ModelCapability::AUDIO/VISION` — all per grounding [3],[4],[9],[10],[11],[14],[16],[18],[20]. ✔
- Table columns — `file_uid`, `subtitle_file_uid`, `source_html`, `script_text`, `status`, `metadata`, `error_message`, `variant` — all from Plan 1 `ext_tables.sql`; no new columns introduced. ✔

### Placeholder scan

- No `TODO`, `TBD`, `FIXME`, "similar to above", "not implemented", or stub bodies in any generator, helper, template, config, or test. Every code step is complete real code; every run step shows the exact command and the expected FAIL/PASS/Skipped output.
- Every Specialized nr-llm call (TTS, FAL) is preceded by `specializedAllowed()` (budget `check()->allowed` AND `isAvailable()`); the over-budget / unavailable branch records a `failed` artifact and returns `false` without making the provider call — asserted by the podcast and schaubild unit tests.
- All render/ffmpeg/Poppler/nr-llm Specialized + Feature services sit behind interfaces or are public injectable classes and are faked in every unit test; real calls are confined to the OPTIONAL, env-gated Task 8 smoke tests.
- Commits: all use `git commit -s` (DCO), English messages, no AI/bot attribution, no `Co-Authored-By`, no emojis.

### Risks / notes for the implementer

- The `JobFileStorage` and `JobProcessingRepository` fakes in the unit tests subclass the real classes and declare a no-arg `__construct()` to skip the parent constructor; this works only while those methods are not `final`. Plan 1 declares both as `final class` with non-final methods — confirm the methods overridden (`store`, `insertArtifact`, `updateArtifact`) are not `final` before relying on the subclass-fake approach; if they are, switch those fakes to `createMock()` / a hand-written interface seam. The generators only depend on the public methods, so either approach is equivalent for the test.
- `TextToSpeechService` / `FalImageService` are `final` concrete nr-llm classes in some versions; if `extends` fails in the fakes, replace the anonymous subclass with PHPUnit `createMock(TextToSpeechService::class)` and stub the same methods — the generator code is unchanged.
- `StandaloneView` is deprecated in favour of the v14 ViewFactory in some setups; `AbstractGenerator::renderTemplate()` isolates the choice in one place. If the project standard is `ViewFactoryInterface`, swap the body of `renderTemplate()` only — the seam keeps generators and their unit tests untouched.
