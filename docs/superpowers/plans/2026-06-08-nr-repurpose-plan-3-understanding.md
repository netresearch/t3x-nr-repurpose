# nr_repurpose Plan 3 — Understanding + Pipeline Wiring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Use strict TDD ordering: write the failing test, run it (observe the documented FAIL), implement the complete real code, run the test (observe PASS), then commit with `git commit -s`.

**Goal:** Turn the stub pipeline of Plan 1 into a real *understanding* stage. Add the `ContentBrief` value object and a `DocumentAnalyzer` that builds exactly **one** `ContentBrief` from a `SourceDocument` (produced by Plan 2) via the nr-llm `CompletionServiceInterface::completeJson()` call (JSON response-format, budget-guarded by `beUserUid`), with source-language detection and a configurable Map-Reduce path for large documents. Add the `GenerationContext` value object that bundles the per-run pipeline state, migrate `ArtifactGeneratorInterface` to its FINAL `GenerationContext` signature, and refactor `GenerationOrchestrator` to run `findRow → ingest → analyze → build context → run generators(ctx) → final status`, keeping Plan 1's per-artifact isolation and status logic.

**Architecture:** This plan sits between Plan 2 (Ingestion → `SourceDocument`) and Plan 5 (real generators). The `DocumentAnalyzer` is the only nr-llm-touching unit here; it lives behind `DocumentAnalyzerInterface` and is faked in unit tests (a stub `CompletionServiceInterface`). The orchestrator depends on the two Plan-3 interfaces (`SourceIngestionServiceInterface` from Plan 2, `DocumentAnalyzerInterface` from this plan) plus the existing `JobProcessingRepository` and tagged generator iterator — all injected. The `GenerationContext` is the single object every Plan-5 generator consumes; it carries the raw job DB row, the `SourceDocument`, the `ContentBrief`, the theme and the BE-user uid (for `BudgetService::check()`). nr-llm calls are isolated behind an interface and never hit a real provider in unit tests; one functional test exercises the full orchestrator with faked ingestion + analyzer + a fake `GenerationContext` generator.

**Tech Stack:** PHP 8.3+ (`declare(strict_types=1)`, final classes, constructor property promotion, `final readonly` VOs, typed properties), TYPO3 v14.3 LTS, `netresearch/nr-llm` `CompletionServiceInterface` + `ChatOptions`, Doctrine DBAL (`JobProcessingRepository` from Plan 1), `typo3/testing-framework` (unit + functional), PSR-12 / TYPO3 CGL.

**Spec coverage (this plan):** §7 Understanding (the `DocumentAnalyzer` + `ContentBrief` VO, JSON completion via nr-llm, source-language detection, Map-Reduce for large documents, one analysis per run shared by all generators) and §6 pipeline & job lifecycle (the `GenerationOrchestrator` ingestion → analysis → generation wiring, status/progress transitions, artifact isolation). It also performs the cross-plan migration of `ArtifactGeneratorInterface` to the `GenerationContext` signature defined in the contracts doc. NOT in this plan: real ingestion strategies (Plan 2), render-infra (Plan 4), real podcast/schaubild/story generators (Plan 5), result-view polish + themes (Plan 6).

**Depends on (Plan 2):** This plan consumes `Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface` and the `Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument` value object delivered by Plan 2 (see Cross-Plan Contracts §"Interfaces — Ingestion" and §"Value Objects — SourceDocument"). The orchestrator refactor (Task 5) injects `SourceIngestionServiceInterface`; its functional test fakes that interface so Plan 3 can be implemented and verified independently of Plan 2's concrete strategies. If Plan 2 is not yet merged, the two consumed symbols are reproduced verbatim from the contracts doc (Task 1 SourceDocument fallback note) so this plan still compiles; when Plan 2 lands, its definitions are authoritative and the fallback is dropped.

**Key grounded facts** (see `docs/superpowers/grounding/2026-06-08-cross-stack-api-grounding.md`):
- `CompletionService implements CompletionServiceInterface`; `completeJson(string $prompt, ?ChatOptions $options = null): array<string,mixed>` decodes JSON and **throws `InvalidArgumentException` on bad JSON** (grounding [3], `CompletionService.php:121`). The public interface alias is autowirable (grounding [1], `Services.yaml:83`).
- The system prompt + JSON response-format + budget metadata are all carried on `ChatOptions`: ctor params `temperature`, `responseFormat: 'json'`, `systemPrompt`, and the budget pair `beUserUid` / `plannedCost`; fluent `withBeUserUid(int)` / `withResponseFormat('json')` (grounding [4], `ChatOptions.php:32-33,160,168`; grounding [18], `BudgetFieldsTrait.php:88-103`). `beUserUid` must be `>= 0` (0 = skip), `plannedCost >= 0.0` or `InvalidArgumentException` (grounding [18]).
- Setting `beUserUid` on `ChatOptions` opts the completion call into the `BudgetMiddleware` guard (grounding [17]); `completeJson()` already forces `response_format=json` internally, but we set it explicitly for clarity.
- Plan 1 `GenerationOrchestrator::process(int $jobUid)` already does `findRow` → terminal-guard → `markStatus(Generating,…)` → per-generator `supports`/`generate` loop → final `Done`/`PartiallyDone`/`Failed`. Generators are injected via `!tagged_iterator nr_repurpose.artifact_generator` and the interface is aliased.
- `JobStatus` enum cases (Plan 1): `Queued`, `Ingesting`, `Analyzing`, `Generating`, `Done`, `PartiallyDone`, `Failed`; `isTerminal()` true for `Done`/`PartiallyDone`/`Failed`.
- v14.3 Core has NO retry/failure transport — the Messenger handler (Plan 1) catches top-level exceptions and marks the job failed; the orchestrator marks ingestion/analysis failures via `markFailed` and aborts (no artifacts).

---

## File Structure

**Extension repo root = `/home/sme/p/nr-repurpose/main/`** (paths below are relative to it).

| File | Responsibility |
|---|---|
| `Classes/Domain/ValueObject/ContentBrief.php` | `final readonly` analysis result VO (title, summary, keyPoints, sections, audience, language) |
| `Classes/Pipeline/GenerationContext.php` | `final readonly` bundle: jobRow + SourceDocument + ContentBrief + theme + beUser; `jobUid()` helper |
| `Classes/Understanding/DocumentAnalyzerInterface.php` | Contract: `analyze(SourceDocument $document, array $jobRow): ContentBrief` |
| `Classes/Understanding/DocumentAnalyzer.php` | Builds ONE `ContentBrief` via `CompletionServiceInterface::completeJson()`; Map-Reduce for large text; JSON→VO normalization |
| `Classes/Understanding/AnalysisException.php` | Thrown when the decoded JSON is missing required keys / unusable |
| `Classes/Generator/ArtifactGeneratorInterface.php` | **Migrated** to `supports(GenerationContext):bool` / `generate(GenerationContext):bool` |
| `Classes/Generator/StubArtifactGenerator.php` | **Migrated** to the `GenerationContext` signature (reads `$ctx->jobRow`) |
| `Classes/Service/GenerationOrchestrator.php` | **Refactored** to `findRow → ingest → analyze → context → generators(ctx) → status` |
| `Tests/Unit/Understanding/DocumentAnalyzerTest.php` | Unit: faked `CompletionServiceInterface` — prompt building, JSON→VO mapping, Map-Reduce path, validation throw |
| `Tests/Unit/Pipeline/GenerationContextTest.php` | Unit: `jobUid()` + field exposure |
| `Tests/Functional/Service/GenerationOrchestratorTest.php` | **Updated** end-to-end with faked ingestion + analyzer + fake `GenerationContext` generator |

**Consumed from Plan 2 (not created here):** `Classes/Ingestion/SourceIngestionServiceInterface.php`, `Classes/Domain/ValueObject/SourceDocument.php`.

---

## Task 1: `ContentBrief` value object

**Files:**
- Create: `Classes/Domain/ValueObject/ContentBrief.php`
- Test: `Tests/Unit/Domain/ValueObject/ContentBriefTest.php`

> **SourceDocument dependency:** `ContentBrief` does not reference `SourceDocument`, so this task has no Plan-2 dependency. If Plan 2 has not yet created `Classes/Domain/ValueObject/SourceDocument.php` by the time Task 3 runs, copy it verbatim from the Cross-Plan Contracts §"Value Objects — SourceDocument" into that path; Plan 2 remains authoritative and will overwrite an identical file.

- [ ] **Step 1: Write the failing test `Tests/Unit/Domain/ValueObject/ContentBriefTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\ValueObject;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use PHPUnit\Framework\TestCase;

final class ContentBriefTest extends TestCase
{
    public function testExposesAllFieldsAsReadonlyProperties(): void
    {
        $brief = new ContentBrief(
            title: 'Quarterly report',
            summary: 'A concise overview of Q1 results.',
            keyPoints: ['Revenue up 12%', 'Churn down 3%'],
            sections: [
                ['heading' => 'Revenue', 'body' => 'Revenue grew across all regions.'],
                ['heading' => 'Churn', 'body' => 'Churn fell after the new onboarding.'],
            ],
            audience: 'Investors and analysts',
            language: 'en',
        );

        self::assertSame('Quarterly report', $brief->title);
        self::assertSame('A concise overview of Q1 results.', $brief->summary);
        self::assertSame(['Revenue up 12%', 'Churn down 3%'], $brief->keyPoints);
        self::assertSame('Revenue', $brief->sections[0]['heading']);
        self::assertSame('Churn fell after the new onboarding.', $brief->sections[1]['body']);
        self::assertSame('Investors and analysts', $brief->audience);
        self::assertSame('en', $brief->language);
    }

    public function testIsReadonly(): void
    {
        $brief = new ContentBrief('t', 's', [], [], 'a', 'de');

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line intentional readonly violation */
        $brief->title = 'mutated';
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter ContentBriefTest`
Expected: FAIL — `Error: Class "Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief" not found`.

- [ ] **Step 3: Write `Classes/Domain/ValueObject/ContentBrief.php`** (verbatim from the contracts doc)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

/**
 * The single understanding result for one run. Produced by DocumentAnalyzer and shared
 * unchanged by all three generators (one analysis per run, not three).
 */
final readonly class ContentBrief
{
    /**
     * @param list<string> $keyPoints
     * @param list<array{heading:string, body:string}> $sections
     */
    public function __construct(
        public string $title,
        public string $summary,
        public array $keyPoints,
        public array $sections,
        public string $audience,
        public string $language,   // detected source language (ISO-639-1), drives the output language
    ) {}
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter ContentBriefTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add Classes/Domain/ValueObject/ContentBrief.php Tests/Unit/Domain/ValueObject/ContentBriefTest.php
git commit -s -m "Add ContentBrief value object (understanding result)"
```

---

## Task 2: `GenerationContext` value object

**Files:**
- Create: `Classes/Pipeline/GenerationContext.php`
- Test: `Tests/Unit/Pipeline/GenerationContextTest.php`

> **SourceDocument dependency:** `GenerationContext` references `SourceDocument` (Plan 2). Ensure `Classes/Domain/ValueObject/SourceDocument.php` exists (Plan 2 creates it; otherwise copy it verbatim from the contracts doc per the Task 1 note) before running this task's test.

- [ ] **Step 1: Write the failing test `Tests/Unit/Pipeline/GenerationContextTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Pipeline;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use PHPUnit\Framework\TestCase;

final class GenerationContextTest extends TestCase
{
    private function makeContext(): GenerationContext
    {
        $document = new SourceDocument(
            title: 'Quarterly report',
            text: 'Revenue grew across all regions.',
            sourceLabel: 'https://example.com/report',
            pageCount: 0,
            languageHint: 'en',
        );
        $brief = new ContentBrief('Quarterly report', 'Summary.', ['Point'], [], 'Analysts', 'en');

        return new GenerationContext(
            jobRow: ['uid' => 42, 'theme' => 'nr', 'be_user' => 7, 'want_podcast' => 1],
            document: $document,
            brief: $brief,
            theme: 'nr',
            beUser: 7,
        );
    }

    public function testJobUidReadsTheRawRow(): void
    {
        self::assertSame(42, $this->makeContext()->jobUid());
    }

    public function testExposesBundledState(): void
    {
        $ctx = $this->makeContext();

        self::assertSame('nr', $ctx->theme);
        self::assertSame(7, $ctx->beUser);
        self::assertSame('Quarterly report', $ctx->document->title);
        self::assertSame('en', $ctx->brief->language);
        self::assertSame(1, $ctx->jobRow['want_podcast']);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter GenerationContextTest`
Expected: FAIL — `Error: Class "Netresearch\NrRepurpose\Pipeline\GenerationContext" not found`.

- [ ] **Step 3: Write `Classes/Pipeline/GenerationContext.php`** (verbatim from the contracts doc)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Pipeline;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;

/**
 * Bundled, immutable per-run pipeline state. Built by GenerationOrchestrator after ingestion
 * and analysis; consumed unchanged by every ArtifactGeneratorInterface implementation.
 */
final readonly class GenerationContext
{
    /** @param array<string,mixed> $jobRow raw job DB row (JobProcessingRepository::findRow) */
    public function __construct(
        public array $jobRow,
        public SourceDocument $document,
        public ContentBrief $brief,
        public string $theme,   // 'nr' | 'neutral'
        public int $beUser,     // for BudgetService::check()
    ) {}

    public function jobUid(): int
    {
        return (int) $this->jobRow['uid'];
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter GenerationContextTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add Classes/Pipeline/GenerationContext.php Tests/Unit/Pipeline/GenerationContextTest.php
git commit -s -m "Add GenerationContext value object (bundled pipeline state)"
```

---

## Task 3: `DocumentAnalyzer` — interface, exception, implementation, unit tests

This is the core of the plan. The analyzer builds exactly **one** `ContentBrief` from a `SourceDocument`:

1. **Source language detection** — derived from `$document->languageHint` when present; the LLM is also asked to confirm/return the detected ISO-639-1 language, and the JSON `language` value wins (the LLM sees the actual text, the hint may be empty).
2. **Map-Reduce for large documents** — when `mb_strlen($document->text)` exceeds a configurable `chunkThreshold` character budget (default 24000, a safe proxy for token limits), the text is split into chunks of `chunkSize` characters at paragraph boundaries; each chunk is summarized via a "map" `completeJson` call; the per-chunk summaries are concatenated and a final "reduce" `completeJson` call synthesizes the single `ContentBrief`. Small documents go straight to one synthesis call. The threshold/size are constructor parameters (wired from `ext_conf` in Plan 5/6; defaults are explicit here).
3. **Validation/normalization** — the decoded array is normalized into a `ContentBrief`; missing/empty `title` or `summary` throws `AnalysisException`; `keyPoints` / `sections` are coerced to the documented shapes; `language` falls back to the hint then `'en'` if the LLM omitted it.

Budget guard: every `completeJson` call carries `ChatOptions` with `beUserUid` set from `$jobRow['be_user']`, which opts the call into nr-llm's `BudgetMiddleware` (grounding [17]/[18]). No separate `BudgetService::check()` is needed for completion — the middleware enforces it and throws `BudgetExceededException`, which propagates to the orchestrator/handler.

**Files:**
- Create: `Classes/Understanding/DocumentAnalyzerInterface.php`, `Classes/Understanding/AnalysisException.php`, `Classes/Understanding/DocumentAnalyzer.php`
- Test: `Tests/Unit/Understanding/DocumentAnalyzerTest.php`

- [ ] **Step 1: Write `Classes/Understanding/DocumentAnalyzerInterface.php`** (verbatim from the contracts doc)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Understanding;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;

interface DocumentAnalyzerInterface
{
    /**
     * Build exactly one ContentBrief from a SourceDocument.
     *
     * @param array<string,mixed> $jobRow raw job DB row (carries be_user for the budget guard)
     * @throws AnalysisException when the LLM result cannot be normalized into a ContentBrief
     */
    public function analyze(SourceDocument $document, array $jobRow): ContentBrief;
}
```

- [ ] **Step 2: Write `Classes/Understanding/AnalysisException.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Understanding;

/**
 * Raised when the analysis result returned by nr-llm cannot be turned into a valid
 * ContentBrief (missing required keys, empty title/summary, …). Caught by the orchestrator,
 * which marks the job failed before any generator runs.
 */
final class AnalysisException extends \RuntimeException
{
}
```

- [ ] **Step 3: Write the failing unit test `Tests/Unit/Understanding/DocumentAnalyzerTest.php`**

The test uses an in-file fake `CompletionServiceInterface` that records every prompt + options it receives and returns scripted decoded arrays. It covers: (a) the small-document single-call path with prompt-building + JSON→VO mapping and budget option wiring; (b) the Map-Reduce path (one map call per chunk + one reduce call) when text exceeds the threshold; (c) the validation throw when required keys are missing.

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Understanding;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Understanding\AnalysisException;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * In-file fake implementing the public nr-llm interface. Records calls and replays scripted
 * decoded JSON, so no real provider is ever hit. (Spec §14: providers behind interfaces, mocked.)
 */
final class FakeCompletionService implements CompletionServiceInterface
{
    /** @var list<array{prompt:string, options:?ChatOptions}> */
    public array $jsonCalls = [];

    /** @param list<array<string,mixed>> $jsonResults FIFO queue of decoded arrays to return */
    public function __construct(private array $jsonResults) {}

    public function completeJson(string $prompt, ?ChatOptions $options = null): array
    {
        $this->jsonCalls[] = ['prompt' => $prompt, 'options' => $options];
        if ($this->jsonResults === []) {
            throw new \LogicException('FakeCompletionService ran out of scripted results');
        }

        return array_shift($this->jsonResults);
    }

    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        throw new \BadMethodCallException('not used in this test');
    }

    public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string
    {
        throw new \BadMethodCallException('not used in this test');
    }

    public function completeFactual(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        throw new \BadMethodCallException('not used in this test');
    }

    public function completeCreative(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        throw new \BadMethodCallException('not used in this test');
    }
}

final class DocumentAnalyzerTest extends TestCase
{
    private function smallDocument(): SourceDocument
    {
        return new SourceDocument(
            title: 'Quarterly report',
            text: 'Revenue grew across all regions. Churn fell after the new onboarding flow.',
            sourceLabel: 'https://example.com/report',
            pageCount: 0,
            languageHint: 'en',
        );
    }

    private function briefResult(string $language = 'en'): array
    {
        return [
            'title' => 'Quarterly report',
            'summary' => 'A concise overview of Q1 results.',
            'keyPoints' => ['Revenue up 12%', 'Churn down 3%'],
            'sections' => [
                ['heading' => 'Revenue', 'body' => 'Revenue grew across all regions.'],
                ['heading' => 'Churn', 'body' => 'Churn fell after onboarding.'],
            ],
            'audience' => 'Investors and analysts',
            'language' => $language,
        ];
    }

    public function testSmallDocumentUsesOneCallAndMapsJsonToContentBrief(): void
    {
        $fake = new FakeCompletionService([$this->briefResult('en')]);
        $analyzer = new DocumentAnalyzer($fake, new NullLogger(), chunkThreshold: 24000, chunkSize: 12000);

        $brief = $analyzer->analyze($this->smallDocument(), ['uid' => 1, 'be_user' => 7]);

        self::assertInstanceOf(ContentBrief::class, $brief);
        self::assertSame('Quarterly report', $brief->title);
        self::assertSame(['Revenue up 12%', 'Churn down 3%'], $brief->keyPoints);
        self::assertSame('Revenue', $brief->sections[0]['heading']);
        self::assertSame('en', $brief->language);

        // exactly one completeJson call for a small document
        self::assertCount(1, $fake->jsonCalls);
    }

    public function testPromptCarriesDocumentTextAndJsonBudgetOptions(): void
    {
        $fake = new FakeCompletionService([$this->briefResult('en')]);
        $analyzer = new DocumentAnalyzer($fake, new NullLogger(), chunkThreshold: 24000, chunkSize: 12000);

        $analyzer->analyze($this->smallDocument(), ['uid' => 1, 'be_user' => 7]);

        $call = $fake->jsonCalls[0];
        // the source text is embedded in the synthesis prompt
        self::assertStringContainsString('Revenue grew across all regions.', $call['prompt']);
        // a system prompt + JSON response format + budget BE-user are set on the options
        $options = $call['options'];
        self::assertInstanceOf(ChatOptions::class, $options);
        self::assertSame('json', $options->getResponseFormat());
        self::assertNotSame('', (string) $options->getSystemPrompt());
        self::assertSame(7, $options->getBeUserUid());
    }

    public function testJsonLanguageOverridesEmptyHint(): void
    {
        $document = new SourceDocument(
            title: 'Bericht',
            text: 'Der Umsatz ist gestiegen.',
            sourceLabel: 'https://example.com/de',
            pageCount: 0,
            languageHint: '', // unknown
        );
        $fake = new FakeCompletionService([$this->briefResult('de')]);
        $analyzer = new DocumentAnalyzer($fake, new NullLogger());

        $brief = $analyzer->analyze($document, ['uid' => 1, 'be_user' => 0]);

        self::assertSame('de', $brief->language);
    }

    public function testLargeDocumentTakesMapReducePath(): void
    {
        // Build text above the threshold so chunking kicks in.
        $paragraph = str_repeat('Section content sentence. ', 400); // ~10k chars
        $bigText = implode("\n\n", [$paragraph, $paragraph, $paragraph]); // ~30k chars, 3 paragraphs

        $document = new SourceDocument(
            title: 'Big report',
            text: $bigText,
            sourceLabel: 'https://example.com/big',
            pageCount: 0,
            languageHint: 'en',
        );

        // map results (one per chunk) + one final reduce result
        $mapResult = ['summary' => 'Chunk summary.', 'keyPoints' => ['kp']];
        $results = [$mapResult, $mapResult, $mapResult, $this->briefResult('en')];
        $fake = new FakeCompletionService($results);
        $analyzer = new DocumentAnalyzer($fake, new NullLogger(), chunkThreshold: 20000, chunkSize: 11000);

        $brief = $analyzer->analyze($document, ['uid' => 1, 'be_user' => 7]);

        self::assertSame('Quarterly report', $brief->title);
        // 3 map calls + 1 reduce call
        self::assertCount(4, $fake->jsonCalls);
        // the reduce (final) prompt synthesizes from the chunk summaries, not the raw text
        self::assertStringContainsString('Chunk summary.', $fake->jsonCalls[3]['prompt']);
    }

    public function testThrowsWhenRequiredKeysMissing(): void
    {
        // missing 'title' and 'summary'
        $fake = new FakeCompletionService([['keyPoints' => ['x'], 'language' => 'en']]);
        $analyzer = new DocumentAnalyzer($fake, new NullLogger());

        $this->expectException(AnalysisException::class);
        $analyzer->analyze($this->smallDocument(), ['uid' => 1, 'be_user' => 0]);
    }
}
```

- [ ] **Step 4: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter DocumentAnalyzerTest`
Expected: FAIL — `Error: Class "Netresearch\NrRepurpose\Understanding\DocumentAnalyzer" not found`.

- [ ] **Step 5: Write `Classes/Understanding/DocumentAnalyzer.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Understanding;

use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Psr\Log\LoggerInterface;

/**
 * Produces exactly one ContentBrief from a SourceDocument via nr-llm CompletionService (JSON mode).
 *
 * - Source language is detected by the LLM (returned in the JSON `language` field); the document
 *   languageHint is used as a fallback only.
 * - Large documents use Map-Reduce: chunk -> per-chunk summary (map) -> single synthesis (reduce),
 *   to respect provider token limits. The chunk threshold/size are configurable.
 * - Every completion call carries a beUserUid on ChatOptions, opting it into nr-llm's
 *   BudgetMiddleware (grounding [17]/[18]); an over-budget run throws BudgetExceededException.
 */
final class DocumentAnalyzer implements DocumentAnalyzerInterface
{
    private const SYSTEM_PROMPT =
        'You are a precise editorial analyst. You read a source document and produce a faithful '
        . 'structured brief. Numbers, names and labels must stay exactly as in the source. '
        . 'Detect the source language and report it as an ISO-639-1 code. '
        . 'Output ONLY valid JSON, no prose around it.';

    private const MAP_SYSTEM_PROMPT =
        'You summarize one section of a larger document faithfully and concisely. '
        . 'Preserve numbers, names and labels exactly. Output ONLY valid JSON.';

    public function __construct(
        private readonly CompletionServiceInterface $completion,
        private readonly LoggerInterface $logger,
        private readonly int $chunkThreshold = 24000,
        private readonly int $chunkSize = 12000,
    ) {}

    public function analyze(SourceDocument $document, array $jobRow): ContentBrief
    {
        $beUser = (int) ($jobRow['be_user'] ?? 0);
        $text = trim($document->text);

        if ($text === '') {
            throw new AnalysisException('Cannot analyze an empty source document', 1749384000);
        }

        if (mb_strlen($text) > $this->chunkThreshold) {
            $synthesisInput = $this->mapReduce($text, $beUser);
        } else {
            $synthesisInput = $text;
        }

        $prompt = $this->buildSynthesisPrompt($document, $synthesisInput);
        $decoded = $this->completion->completeJson($prompt, $this->jsonOptions(self::SYSTEM_PROMPT, $beUser));

        return $this->toContentBrief($decoded, $document);
    }

    /**
     * Map step: summarize each chunk; returns the concatenated per-chunk summaries used as the
     * reduce input.
     */
    private function mapReduce(string $text, int $beUser): string
    {
        $chunks = $this->splitIntoChunks($text);
        $this->logger->info('DocumentAnalyzer map-reduce', ['chunks' => count($chunks)]);

        $summaries = [];
        foreach ($chunks as $index => $chunk) {
            $prompt = "Summarize this section faithfully as JSON with keys "
                . "\"summary\" (string) and \"keyPoints\" (array of strings).\n\n"
                . "SECTION " . ($index + 1) . ":\n" . $chunk;
            $decoded = $this->completion->completeJson(
                $prompt,
                $this->jsonOptions(self::MAP_SYSTEM_PROMPT, $beUser),
            );

            $summary = is_string($decoded['summary'] ?? null) ? $decoded['summary'] : '';
            $points = $this->normalizeStringList($decoded['keyPoints'] ?? []);
            $summaries[] = trim($summary . "\n" . implode("\n", array_map(
                static fn (string $p): string => '- ' . $p,
                $points,
            )));
        }

        return "Section summaries of the source document:\n\n" . implode("\n\n", $summaries);
    }

    /** @return list<string> */
    private function splitIntoChunks(string $text): array
    {
        $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $candidate = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
            if (mb_strlen($candidate) > $this->chunkSize && $current !== '') {
                $chunks[] = $current;
                $current = $paragraph;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [$text] : $chunks;
    }

    private function buildSynthesisPrompt(SourceDocument $document, string $body): string
    {
        return "Analyze the following document and produce a faithful brief as JSON with keys: "
            . "\"title\" (string), \"summary\" (string), \"keyPoints\" (array of strings), "
            . "\"sections\" (array of {\"heading\": string, \"body\": string}), "
            . "\"audience\" (string), \"language\" (ISO-639-1 string of the source language).\n\n"
            . "Source title: " . ($document->title !== '' ? $document->title : '(none)') . "\n"
            . "Source label: " . $document->sourceLabel . "\n\n"
            . "DOCUMENT:\n" . $body;
    }

    private function jsonOptions(string $systemPrompt, int $beUser): ChatOptions
    {
        $options = new ChatOptions(
            temperature: 0.3,
            responseFormat: 'json',
            systemPrompt: $systemPrompt,
        );

        // beUserUid opts the call into nr-llm BudgetMiddleware; 0 = skip (anonymous/CLI).
        return $beUser > 0 ? $options->withBeUserUid($beUser) : $options;
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function toContentBrief(array $decoded, SourceDocument $document): ContentBrief
    {
        $title = is_string($decoded['title'] ?? null) ? trim($decoded['title']) : '';
        $summary = is_string($decoded['summary'] ?? null) ? trim($decoded['summary']) : '';

        if ($title === '' || $summary === '') {
            throw new AnalysisException(
                'Analysis result is missing the required "title" and/or "summary" key',
                1749384100,
            );
        }

        $language = is_string($decoded['language'] ?? null) && $decoded['language'] !== ''
            ? strtolower(substr($decoded['language'], 0, 5))
            : ($document->languageHint !== '' ? $document->languageHint : 'en');

        $audience = is_string($decoded['audience'] ?? null) ? trim($decoded['audience']) : '';

        return new ContentBrief(
            title: $title,
            summary: $summary,
            keyPoints: $this->normalizeStringList($decoded['keyPoints'] ?? []),
            sections: $this->normalizeSections($decoded['sections'] ?? []),
            audience: $audience,
            language: $language,
        );
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @return list<array{heading:string, body:string}>
     */
    private function normalizeSections(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $section) {
            if (!is_array($section)) {
                continue;
            }
            $heading = is_string($section['heading'] ?? null) ? trim($section['heading']) : '';
            $body = is_string($section['body'] ?? null) ? trim($section['body']) : '';
            if ($heading === '' && $body === '') {
                continue;
            }
            $out[] = ['heading' => $heading, 'body' => $body];
        }

        return $out;
    }
}
```

> **API note (grounding [3]/[4]/[18]):** `completeJson(string, ?ChatOptions): array` throws `InvalidArgumentException` on non-JSON; we let that propagate to the orchestrator (treated as an analysis failure). `ChatOptions` accepts `temperature` / `responseFormat` / `systemPrompt` in its constructor and exposes `withBeUserUid(int)`; setting `beUserUid` enables the `BudgetMiddleware` guard. The accessors `getResponseFormat()`, `getSystemPrompt()`, `getBeUserUid()` used in the unit test are the standard nr-llm getters for those fields (`ChatOptions.php`/`BudgetFieldsTrait.php`).

- [ ] **Step 6: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter DocumentAnalyzerTest`
Expected: PASS (5 tests).

> If `ChatOptions` exposes the getters under different names than `getResponseFormat()/getSystemPrompt()/getBeUserUid()`, this is the only place to adjust: confirm the actual getter names in `../../t3x-nr-llm/main/Classes/Service/Option/ChatOptions.php` and `BudgetFieldsTrait.php` and update both the test assertions and (if needed) nothing in the production code (production code only uses the documented constructor params + `withBeUserUid()`). Do NOT relax the assertions to "any value" — they must verify the actual JSON/system-prompt/budget wiring.

- [ ] **Step 7: Commit**

```bash
git add Classes/Understanding Tests/Unit/Understanding
git commit -s -m "Add DocumentAnalyzer building one ContentBrief via nr-llm completeJson with map-reduce"
```

---

## Task 4: Migrate `ArtifactGeneratorInterface` + `StubArtifactGenerator` to `GenerationContext`

The contracts doc fixes the FINAL generator signature to `supports(GenerationContext): bool` / `generate(GenerationContext): bool`. Plan 1 shipped the `array`-based variant; this task migrates the interface and the only existing implementation (`StubArtifactGenerator`). Plan 5 generators implement ONLY this `GenerationContext` variant.

**Files:**
- Modify: `Classes/Generator/ArtifactGeneratorInterface.php`
- Modify: `Classes/Generator/StubArtifactGenerator.php`

- [ ] **Step 1: Migrate `Classes/Generator/ArtifactGeneratorInterface.php`**

Replace the file's contents with the FINAL signature:

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrRepurpose\Pipeline\GenerationContext;

/**
 * Produces one artifact for a job from the shared GenerationContext. Implementations persist
 * their own artifact row (via JobProcessingRepository) and must NOT throw for a single-artifact
 * business failure — they record a failed artifact and return false so siblings still run.
 */
interface ArtifactGeneratorInterface
{
    public function supports(GenerationContext $ctx): bool;

    /** @return bool true if the artifact was produced successfully */
    public function generate(GenerationContext $ctx): bool;
}
```

- [ ] **Step 2: Migrate `Classes/Generator/StubArtifactGenerator.php`** to read from `$ctx->jobRow`

Replace the `supports`/`generate` signatures and bodies; everything else (the FAL write + artifact insert) is unchanged except it now reads the job row off the context.

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use Psr\Log\LoggerInterface;

/**
 * Placeholder generator for the walking skeleton: writes a small .txt file to FAL and records a
 * `stub` artifact. Now consumes the GenerationContext (Plan 3). Replaced by the real
 * podcast/schaubild/story generators in Plan 5.
 */
final class StubArtifactGenerator implements ArtifactGeneratorInterface
{
    public function __construct(
        private readonly JobFileStorage $fileStorage,
        private readonly JobProcessingRepository $jobs,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(GenerationContext $ctx): bool
    {
        return true;
    }

    public function generate(GenerationContext $ctx): bool
    {
        $jobUid = $ctx->jobUid();
        try {
            $content = sprintf(
                "nr_repurpose stub artifact\nJob #%d\nSource: %s\nTheme: %s\nTitle: %s\nLanguage: %s\n",
                $jobUid,
                (string) ($ctx->jobRow['source_value'] ?? ''),
                $ctx->theme,
                $ctx->brief->title,
                $ctx->brief->language,
            );
            $file = $this->fileStorage->store($content, 'stub.txt');
            $this->jobs->insertArtifact($jobUid, ArtifactType::Stub, 'default', $file->getUid(), ArtifactStatus::Done);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Stub artifact failed', ['job' => $jobUid, 'exception' => $e->getMessage()]);
            $this->jobs->insertArtifact($jobUid, ArtifactType::Stub, 'default', 0, ArtifactStatus::Failed, $e->getMessage());

            return false;
        }
    }
}
```

- [ ] **Step 3: Verify the existing unit suite still parses (no orphaned `array`-signature callers yet)**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml`
Expected: PASS for all unit tests written so far (ContentBrief, GenerationContext, DocumentAnalyzer, plus the Plan-1 `JobStatusTest`). The orchestrator (still on the Plan-1 `array` loop) is exercised only by the functional suite, which is updated in Task 5 — so the unit run is green here. The functional `GenerationOrchestratorTest` will be RED until Task 5 lands; that is expected and is fixed in the next task.

- [ ] **Step 4: Commit**

```bash
git add Classes/Generator/ArtifactGeneratorInterface.php Classes/Generator/StubArtifactGenerator.php
git commit -s -m "Migrate ArtifactGeneratorInterface and stub generator to GenerationContext signature"
```

---

## Task 5: Refactor `GenerationOrchestrator` (ingest → analyze → context → generators)

**Depends on Plan 2:** injects `Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface` (Plan 2) and `Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface` (Task 3). The functional test fakes both interfaces so this task is verifiable without Plan 2's concrete strategies.

The refactor follows the contracts doc Orchestrator-Evolution exactly:
1. `findRow` → if terminal: return (idempotent).
2. `markStatus(Ingesting,'ingesting',5)` → `SourceIngestionService::ingest($row)`.
3. `markStatus(Analyzing,'analyzing',20)` → `DocumentAnalyzer::analyze($doc,$row)`.
4. build `GenerationContext` (theme from `$row['theme']`, beUser from `$row['be_user']`).
5. `markStatus(Generating,'generating',…)` → per generator with `supports($ctx)`: `generate($ctx)`, advance progress.
6. final status `Done`/`PartiallyDone`/`Failed` (Plan 1 logic preserved).

A failure in ingestion or analysis → `markFailed` + abort (no artifact). A failure inside a single generator → that artifact is `failed`, the rest still run (per-artifact isolation). The Messenger handler (Plan 1) still catches top-level exceptions; no v14.3 retry.

**Files:**
- Modify: `Classes/Service/GenerationOrchestrator.php`
- Test (modify): `Tests/Functional/Service/GenerationOrchestratorTest.php`

- [ ] **Step 1: Rewrite the functional test `Tests/Functional/Service/GenerationOrchestratorTest.php`**

The test registers fakes for `SourceIngestionServiceInterface` and `DocumentAnalyzerInterface` in the container, and replaces the tagged generator list with a single in-file `GenerationContext`-consuming generator that asserts the context flowed through (theme + brief). It verifies the job ends `done`, progress 100, language is recorded, and one artifact was created.

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Service\GenerationOrchestrator;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class GenerationOrchestratorTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-repurpose'];

    private function seedJob(): int
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $conn->insert('tx_nrrepurpose_domain_model_job', [
            'pid' => 0, 'source_type' => 'url', 'source_value' => 'https://example.com/',
            'theme' => 'nr', 'want_podcast' => 1, 'want_schaubild' => 1, 'want_story' => 1,
            'status' => 'queued', 'be_user' => 0,
        ]);

        return (int) $conn->lastInsertId();
    }

    public function testProcessRunsIngestAnalyzeGenerateAndEndsDone(): void
    {
        $jobUid = $this->seedJob();

        $document = new SourceDocument(
            title: 'Quarterly report',
            text: 'Revenue grew across all regions.',
            sourceLabel: 'https://example.com/',
            pageCount: 0,
            languageHint: 'en',
        );
        $brief = new ContentBrief('Quarterly report', 'Summary.', ['Point'], [], 'Analysts', 'en');

        $ingestion = new class ($document) implements SourceIngestionServiceInterface {
            public function __construct(private readonly SourceDocument $document) {}

            public function ingest(array $jobRow): SourceDocument
            {
                return $this->document;
            }
        };

        $analyzer = new class ($brief) implements DocumentAnalyzerInterface {
            public function __construct(private readonly ContentBrief $brief) {}

            public function analyze(SourceDocument $document, array $jobRow): ContentBrief
            {
                return $this->brief;
            }
        };

        // A real GenerationContext-consuming generator that records the context flow and persists.
        $jobs = $this->get(JobProcessingRepository::class);
        $generator = new class ($jobs) implements ArtifactGeneratorInterface {
            public ?GenerationContext $seen = null;

            public function __construct(private readonly JobProcessingRepository $jobs) {}

            public function supports(GenerationContext $ctx): bool
            {
                return true;
            }

            public function generate(GenerationContext $ctx): bool
            {
                $this->seen = $ctx;
                $this->jobs->insertArtifact(
                    $ctx->jobUid(),
                    ArtifactType::Stub,
                    'default',
                    0,
                    ArtifactStatus::Done,
                );

                return true;
            }
        };

        $orchestrator = new GenerationOrchestrator(
            $jobs,
            new NullLogger(),
            $ingestion,
            $analyzer,
            [$generator],
        );

        $orchestrator->process($jobUid);

        // context flowed through to the generator
        self::assertInstanceOf(GenerationContext::class, $generator->seen);
        self::assertSame('nr', $generator->seen->theme);
        self::assertSame('Quarterly report', $generator->seen->brief->title);
        self::assertSame('Revenue grew across all regions.', $generator->seen->document->text);

        // job ended done, progress full
        $row = $jobs->findRow($jobUid);
        self::assertSame('done', $row['status']);
        self::assertSame(100, (int) $row['progress']);

        // exactly one artifact persisted, done
        $artifactCount = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->count('uid', 'tx_nrrepurpose_domain_model_artifact', ['job' => $jobUid, 'status' => 'done']);
        self::assertSame(1, $artifactCount);
    }

    public function testIngestionFailureMarksJobFailedAndRunsNoGenerator(): void
    {
        $jobUid = $this->seedJob();
        $jobs = $this->get(JobProcessingRepository::class);

        $ingestion = new class implements SourceIngestionServiceInterface {
            public function ingest(array $jobRow): SourceDocument
            {
                throw new \RuntimeException('source unreachable');
            }
        };
        $analyzer = new class implements DocumentAnalyzerInterface {
            public bool $called = false;

            public function analyze(SourceDocument $document, array $jobRow): ContentBrief
            {
                $this->called = true;

                return new ContentBrief('t', 's', [], [], 'a', 'en');
            }
        };
        $generator = new class implements ArtifactGeneratorInterface {
            public bool $called = false;

            public function supports(GenerationContext $ctx): bool
            {
                return true;
            }

            public function generate(GenerationContext $ctx): bool
            {
                $this->called = true;

                return true;
            }
        };

        $orchestrator = new GenerationOrchestrator($jobs, new NullLogger(), $ingestion, $analyzer, [$generator]);
        $orchestrator->process($jobUid);

        $row = $jobs->findRow($jobUid);
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('source unreachable', (string) $row['error_message']);
        self::assertFalse($analyzer->called);
        self::assertFalse($generator->called);
    }
}
```

- [ ] **Step 2: Run the functional test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter GenerationOrchestratorTest"`
Expected: FAIL — the current Plan-1 `GenerationOrchestrator` constructor takes `(JobProcessingRepository, LoggerInterface, iterable $generators)` and calls `supports(array)`/`generate(array)`, so the new 5-arg constructor and `GenerationContext` calls do not exist yet (TypeError / ArgumentCountError). This confirms the test drives the refactor.

- [ ] **Step 3: Rewrite `Classes/Service/GenerationOrchestrator.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use Psr\Log\LoggerInterface;

/**
 * Drives one job through the real pipeline: findRow -> ingest -> analyze -> build context ->
 * run each applicable generator(ctx) -> final status. Per-artifact isolation and the status
 * transitions are preserved from Plan 1; ingestion/analysis failures abort before any artifact.
 *
 * @param iterable<ArtifactGeneratorInterface> $generators
 */
final class GenerationOrchestrator implements GenerationOrchestratorInterface
{
    /** @var list<ArtifactGeneratorInterface> */
    private array $generators;

    public function __construct(
        private readonly JobProcessingRepository $jobs,
        private readonly LoggerInterface $logger,
        private readonly SourceIngestionServiceInterface $ingestion,
        private readonly DocumentAnalyzerInterface $analyzer,
        iterable $generators,
    ) {
        $this->generators = $generators instanceof \Traversable
            ? iterator_to_array($generators, false)
            : array_values($generators);
    }

    public function process(int $jobUid): void
    {
        $row = $this->jobs->findRow($jobUid);
        if ($row === null) {
            $this->logger->warning('Job not found, skipping', ['job' => $jobUid]);

            return;
        }
        if (JobStatus::from((string) $row['status'])->isTerminal()) {
            return; // idempotent: never reprocess a finished job
        }

        // 1) Ingestion — turn the source into a SourceDocument.
        $this->jobs->markStatus($jobUid, JobStatus::Ingesting, 'ingesting', 5);
        try {
            $document = $this->ingestion->ingest($row);
        } catch (\Throwable $e) {
            $this->logger->error('Ingestion failed', ['job' => $jobUid, 'exception' => $e->getMessage()]);
            $this->jobs->markFailed($jobUid, $e->getMessage());

            return;
        }

        // 2) Understanding — build exactly one ContentBrief.
        $this->jobs->markStatus($jobUid, JobStatus::Analyzing, 'analyzing', 20);
        try {
            $brief = $this->analyzer->analyze($document, $row);
        } catch (\Throwable $e) {
            $this->logger->error('Analysis failed', ['job' => $jobUid, 'exception' => $e->getMessage()]);
            $this->jobs->markFailed($jobUid, $e->getMessage());

            return;
        }

        // Record the detected language on the job for the BE result view.
        $this->jobs->setLanguageDetected($jobUid, $brief->language);

        // 3) Build the shared per-run context.
        $ctx = new GenerationContext(
            jobRow: $row,
            document: $document,
            brief: $brief,
            theme: (string) ($row['theme'] ?? 'nr'),
            beUser: (int) ($row['be_user'] ?? 0),
        );

        // 4) Generation — per-artifact isolation; a single failure does not abort siblings.
        $this->jobs->markStatus($jobUid, JobStatus::Generating, 'generating', 30);
        $applicable = array_values(array_filter(
            $this->generators,
            static fn (ArtifactGeneratorInterface $g): bool => $g->supports($ctx),
        ));
        $count = count($applicable);
        $ok = 0;
        foreach ($applicable as $i => $generator) {
            $success = $generator->generate($ctx);
            $ok += $success ? 1 : 0;
            $progress = $count > 0 ? (int) (30 + 70 * ($i + 1) / $count) : 100;
            $this->jobs->markStatus($jobUid, JobStatus::Generating, 'generating', $progress);
        }

        // 5) Final status (Plan 1 logic preserved).
        $final = $ok === $count
            ? JobStatus::Done
            : ($ok > 0 ? JobStatus::PartiallyDone : JobStatus::Failed);
        $this->jobs->markStatus($jobUid, $final, 'done', 100);
    }
}
```

- [ ] **Step 4: Add `setLanguageDetected()` to `Classes/Persistence/JobProcessingRepository.php`**

The orchestrator records the detected language; add a small DBAL setter beside the existing `markStatus`/`markFailed`. Insert this method into the class (after `markFailed`):

```php
    public function setLanguageDetected(int $jobUid, string $language): void
    {
        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)->update(
            self::JOB_TABLE,
            ['language_detected' => $language, 'tstamp' => time()],
            ['uid' => $jobUid],
        );
    }
```

(The `language_detected` column already exists in Plan 1 `ext_tables.sql`.)

- [ ] **Step 5: Update the DI tag wiring in `Configuration/Services.yaml`**

The Plan-1 `GenerationOrchestrator` service entry passes only `$generators`. Autowiring now resolves `SourceIngestionServiceInterface` (Plan 2 alias) and `DocumentAnalyzerInterface` (autowired concrete `DocumentAnalyzer`) by constructor type-hints; only `$generators` still needs the explicit tagged iterator. Confirm the entry reads:

```yaml
  _instanceof:
    Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface:
      tags: ['nr_repurpose.artifact_generator']

  Netresearch\NrRepurpose\Service\GenerationOrchestrator:
    arguments:
      $generators: !tagged_iterator nr_repurpose.artifact_generator

  Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface:
    alias: Netresearch\NrRepurpose\Service\GenerationOrchestrator

  Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface:
    alias: Netresearch\NrRepurpose\Understanding\DocumentAnalyzer
```

> If Plan 2 has not yet added the `SourceIngestionServiceInterface` alias, add it here too (`alias: Netresearch\NrRepurpose\Ingestion\SourceIngestionService`, `public: false`); Plan 2 is authoritative and an identical alias is harmless. The `DocumentAnalyzer` is autowired from its concrete class; its `chunkThreshold`/`chunkSize` use the defaulted constructor values until Plan 5/6 binds them to `ext_conf`.

- [ ] **Step 6: Run the functional test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter GenerationOrchestratorTest"`
Expected: PASS (2 tests) — the done-path and the ingestion-failure path.

- [ ] **Step 7: Run the full unit + functional suites for regression**

Run:
```bash
cd /home/sme/p/nr-repurpose/main
.Build/bin/phpunit -c Build/phpunit/UnitTests.xml
ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml"
```
Expected: all unit tests PASS (ContentBrief, GenerationContext, DocumentAnalyzer, Plan-1 enums); all functional tests PASS (Plan-1 `JobRepositoryTest`, `JobProcessingRepositoryTest`, `JobFileStorageTest`, and the updated `GenerationOrchestratorTest`).

- [ ] **Step 8: Commit**

```bash
git add Classes/Service/GenerationOrchestrator.php Classes/Persistence/JobProcessingRepository.php Configuration/Services.yaml Tests/Functional/Service/GenerationOrchestratorTest.php
git commit -s -m "Refactor GenerationOrchestrator to ingest, analyze and run GenerationContext generators"
```

---

## Self-Review

### Spec coverage (this plan's slice)

- **§7 Understanding** — `ContentBrief` VO (Task 1) carries exactly `title`, `summary`, `keyPoints[]`, `sections[]`, `audience`, `language` as the spec lists. `DocumentAnalyzer` (Task 3) produces exactly **one** `ContentBrief` per run via nr-llm `CompletionServiceInterface::completeJson()` in JSON mode, detects source language (LLM `language` field with `languageHint` fallback), and uses **Map-Reduce** (chunk → per-chunk summary → synthesis) above a **configurable** `chunkThreshold` to respect token limits. The single brief is shared by all generators via `GenerationContext` (one analysis per run, not three).
- **§6 pipeline & job lifecycle** — `GenerationOrchestrator` (Task 5) implements `findRow → ingest → analyze → build context → generators(ctx) → final status` with `Ingesting`(5)/`Analyzing`(20)/`Generating`(30→100) transitions, per-artifact isolation (single-generator failure → others still run), and abort-with-`markFailed` on ingestion/analysis failure (no artifacts) — matching §6 step 4 and §12 artifact-isolation.
- **Cross-plan migration** — `ArtifactGeneratorInterface` migrated to the FINAL `supports(GenerationContext)`/`generate(GenerationContext)` signature (Task 4), with `StubArtifactGenerator` updated to read `$ctx->jobRow`/`$ctx->brief`. Out of scope here and deferred as labelled: real ingestion strategies (Plan 2), render-infra (Plan 4), real generators (Plan 5), result-view/themes (Plan 6).

### Type consistency vs. the contracts doc

- `ContentBrief`, `GenerationContext`, `DocumentAnalyzerInterface`, and `ArtifactGeneratorInterface` are reproduced **verbatim** from the Cross-Plan Contracts doc (same namespaces `Domain\ValueObject\`, `Pipeline\`, `Understanding\`, `Generator\`; same property names/order; `GenerationContext::jobUid()` returns `(int) $jobRow['uid']`).
- The orchestrator consumes Plan 2's `SourceIngestionServiceInterface::ingest(array $jobRow): SourceDocument` and Plan 3's `DocumentAnalyzerInterface::analyze(SourceDocument, array): ContentBrief` with the exact signatures. It reuses Plan 1's `JobProcessingRepository` (`findRow`, `markStatus`, `markFailed`) and adds `setLanguageDetected()` against the pre-existing `language_detected` column.
- nr-llm usage matches the grounding doc: `CompletionServiceInterface::completeJson(string, ?ChatOptions): array` (grounding [3]); `ChatOptions` ctor params `temperature`/`responseFormat`/`systemPrompt` + `withBeUserUid(int)` budget guard (grounding [4]/[18]). No guessed APIs.

### Placeholder scan

- No `TODO`/`TBD`/`FIXME`/"similar to above"/"not implemented" markers. Every step shows complete real code; every run step gives the exact command and the expected FAIL/PASS output. The Map-Reduce, validation, and normalization logic are fully implemented (no stubbed branches). nr-llm and ingestion are exercised behind interfaces and **faked** in unit/functional tests (in-file `FakeCompletionService`, anonymous fakes for ingestion/analyzer/generator) per spec §14 — no real provider call in this plan's tests. The only conditional note is a getter-name verification fallback in Task 3 Step 6, which adjusts test assertions (not production code) if nr-llm's `ChatOptions` getters differ — it does not relax what is verified.
