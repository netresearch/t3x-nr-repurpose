# nr_repurpose Plan 2 — Ingestion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This plan builds directly on the finished Plan 1 walking skeleton (same repo root, same DDEV instance, same Job/Artifact tables). It does **not** touch the orchestrator or the Messenger handler — Plan 3 wires ingestion into the pipeline.

**Goal:** Turn a job's source (webpage URL, PDF via URL, or PDF from FAL) into a single canonical `SourceDocument` value object. Add PDF source support to the Job table/TCA/model (`source_pdf` FAL field + `pdf_mode` enum), implement the four ingestion strategies (`WebPageFetcher`, `PdfTextExtractor`, `PdfVisionExtractor`, `PdfLayoutExtractor`) behind their own classes, and assemble them in `SourceIngestionService` (a per-page auto-dispatcher honoring `pdf_mode`). All render/Process/nr-llm calls sit behind interfaces and are faked in unit tests; one functional test ingests a real tiny text PDF fixture and a real static HTML fixture.

**Architecture:** Netresearch TYPO3-extension convention (mirrors `t3x-nr-llm`); the extension repo *is* the Composer root. Ingestion is a self-contained layer under `Netresearch\NrRepurpose\Ingestion\` that consumes a raw job DB row (`array<string,mixed>` from `JobProcessingRepository::findRow`) and produces a `Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument`. External tools are isolated behind seams: PSR-18 `ClientInterface` (web fetch), a `PdfRasterizer`/`PdfLayoutRunner` Symfony-`Process` wrapper interface (Poppler), nr-llm `VisionServiceInterface` (OCR). FAL access for `pdf_fal` sources goes through TYPO3 `ResourceFactory`. The `SourceIngestionService` is the only public entry point (implements `SourceIngestionServiceInterface`); strategy selection follows `source_type` + `pdf_mode` exactly as the grounding doc's PDF auto-dispatcher.

**Tech Stack:** PHP 8.3+ (`declare(strict_types=1)`, final classes, readonly VOs, constructor property promotion, typed properties), TYPO3 v14.3 LTS, `smalot/pdfparser:^2.12`, Poppler `pdftoppm`/`pdftotext` (already baked into `.ddev/web-build` in Plan 1 Task 2), `symfony/process:^7.0` (already required in Plan 1), PSR-18 HTTP client (TYPO3 core `RequestFactory`/Guzzle), `netresearch/nr-llm` `VisionServiceInterface`, `typo3/testing-framework` (unit + functional).

**Spec coverage (this plan):** §7 Ingestion (`SourceIngestionService`, `WebPageFetcher` with Readability-style main-content extraction, `PdfTextExtractor`, `PdfVisionExtractor`, `PdfLayoutExtractor`, the `auto`/`text`/`vision`/`tables` tier dispatcher) and §5 the PDF source fields (`source_pdf` FAL column, `pdf_mode` enum) plus the §11 BE New-form additions for URL-vs-PDF and `pdf_mode`. NOT in this plan: wiring ingestion into `GenerationOrchestrator` (Plan 3 — adds analyze + `GenerationContext`), understanding/`ContentBrief` (Plan 3), render-infra (Plan 4), generators (Plan 5), themes/result view (Plan 6). The Plan 1 `GenerationOrchestrator` and `StubArtifactGenerator` are left **untouched**.

**Key grounded facts** (see `docs/superpowers/grounding/2026-06-08-cross-stack-api-grounding.md`, PDF area lines 704–915 and nr-llm area lines 7–163):
- `smalot/pdfparser` v2.12.5 (2026-04-17); `composer require smalot/pdfparser:^2.12`; requires `ext-zlib` + `ext-iconv`, PHP >=7.1 (grounding [0],[1]).
- smalot API: `new Parser([], $config)`; `$config = new Config(); $config->setIgnoreEncryption(true);` to dodge known false-positive "Secured pdf file" detection; `$doc = $parser->parseFile($path)`; per-page via `$doc->getPages()` (Page[]) and `$page->getText()` (grounding [2],[3],[4]).
- smalot throws `\Exception('Secured pdf file are currently not supported.')` on truly encrypted PDFs even with the ignore flag → catch `\Throwable` and translate (grounding [4]).
- Poppler `pdftoppm -png -r <dpi> -f <p> -l <p> -singlefile <pdf> <prefix>` writes exactly `<prefix>.png`; `pdftotext -layout -f <p> -l <p> -enc UTF-8 -nopgbrk -q <pdf> -` writes layout-preserved text to stdout (grounding [5],[6], code snippets).
- Ghostscript/Imagick are NOT installed and NOT needed — Poppler renders natively (grounding [7],[8]).
- nr-llm `VisionServiceInterface::analyzeImage(string|array $imageUrl, string $customPrompt, ?VisionOptions $options = null): string|array`; accepts a `data:image/png;base64,...` URI; `VisionOptions` has `withMaxTokens(int)` / `withBeUserUid(int)` fluent setters (grounding [7] nr-llm, [9]/[10] PDF area, `VisionService.php:133,235-247`).
- `VisionServiceInterface` is a **public** alias in nr-llm (`Services.yaml:90`) — autowires with no extra registration (grounding nr-llm [1],[2]).
- TCA `type=file` auto-generates its DB column since v13.0 — no `ext_tables.sql` entry needed for `source_pdf` (grounding BE area [11], `Fal/UsingFal/Tca.rst:12-16`). `pdf_mode` is a plain enum column → it DOES need an `ext_tables.sql` entry.

---

## File Structure

**Extension repo root = `/home/sme/p/nr-repurpose/main/`** (paths below are relative to it).

| File | Responsibility |
|---|---|
| `composer.json` | (Modify) add `smalot/pdfparser:^2.12` to `require` |
| `ext_tables.sql` | (Modify) add `pdf_mode` column to the job table |
| `Configuration/TCA/tx_nrrepurpose_domain_model_job.php` | (Modify) add `source_pdf` (type=file, pdf, maxitems 1), `pdf_mode` (select), show in form |
| `Classes/Domain/Enum/PdfMode.php` | `auto`/`text`/`vision`/`tables` backed enum + helpers |
| `Classes/Domain/Model/Job.php` | (Modify) add `pdfMode` + `sourcePdf` (FileReference count) accessors |
| `Classes/Domain/ValueObject/SourceDocument.php` | Ingestion result VO (exact contracts signature) |
| `Classes/Ingestion/IngestionException.php` | Typed exception for unreachable/unreadable sources |
| `Classes/Ingestion/SourceIngestionServiceInterface.php` | `ingest(array $jobRow): SourceDocument` |
| `Classes/Ingestion/SourceIngestionService.php` | Strategy selection + per-page auto tier dispatcher; assembles `SourceDocument` |
| `Classes/Ingestion/WebPageFetcher.php` | PSR-18 fetch + Readability-style main-content extraction |
| `Classes/Ingestion/PdfTextExtractor.php` | smalot per-page text + near-empty (sparse) detection + encryption guard |
| `Classes/Ingestion/PdfVisionExtractor.php` | `pdftoppm` → data URI → nr-llm `VisionService::analyzeImage` |
| `Classes/Ingestion/PdfLayoutExtractor.php` | `pdftotext -layout` per page via Process |
| `Classes/Ingestion/Poppler/PopplerRunnerInterface.php` | Seam over Poppler binaries (rasterize page / extract layout) |
| `Classes/Ingestion/Poppler/SymfonyProcessPopplerRunner.php` | Real `Symfony\Component\Process\Process` implementation |
| `Classes/Ingestion/PdfFileResolver.php` | Resolves `source_type`+row to an absolute local PDF path (FAL or downloaded URL) |
| `Tests/Unit/Domain/ValueObject/SourceDocumentTest.php` | VO immutability/defaults |
| `Tests/Unit/Domain/Enum/PdfModeTest.php` | enum values + `fromJobValue` |
| `Tests/Unit/Ingestion/WebPageFetcherTest.php` | fetch + extraction with a fake PSR-18 client |
| `Tests/Unit/Ingestion/PdfTextExtractorTest.php` | per-page + sparse flag on a real tiny PDF fixture (no provider) |
| `Tests/Unit/Ingestion/PdfVisionExtractorTest.php` | fake `PopplerRunner` + fake `VisionService` |
| `Tests/Unit/Ingestion/PdfLayoutExtractorTest.php` | fake `PopplerRunner` |
| `Tests/Unit/Ingestion/SourceIngestionServiceTest.php` | tier dispatch logic with all seams faked |
| `Tests/Functional/Ingestion/SourceIngestionServiceTest.php` | real text-PDF fixture + real HTML fixture end-to-end |
| `Tests/Fixtures/Pdf/sample-text.pdf` | tiny real 1-page text PDF (generated, committed) |
| `Tests/Fixtures/Web/article.html` | static HTML article fixture |

---

## Task 1: Add smalot/pdfparser dependency

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Add the dependency via composer (network resolution)**

Run:
```bash
cd /home/sme/p/nr-repurpose/main && composer require smalot/pdfparser:^2.12 --no-interaction --no-progress
```
Expected: composer adds `"smalot/pdfparser": "^2.12"` to `require` and resolves `smalot/pdfparser 2.12.x` into `.Build/vendor`. `symfony/process` is already present (Plan 1 `composer.json` require: `"symfony/process": "^7.0"`).

If the environment has no network, instead edit `composer.json` directly to add the line below into the existing `require` block, then run `composer update smalot/pdfparser --no-interaction` inside `ddev exec` when the DDEV instance is up.

```json
        "smalot/pdfparser": "^2.12",
```

- [ ] **Step 2: Verify the require landed**

Run: `cd /home/sme/p/nr-repurpose/main && composer show smalot/pdfparser --no-interaction | head -n 2`
Expected: shows `name : smalot/pdfparser` and a `versions : * 2.12.x` line.

Run: `cd /home/sme/p/nr-repurpose/main && grep -n 'smalot/pdfparser' composer.json`
Expected: one line inside the `require` block.

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -s -m "Add smalot/pdfparser dependency for PDF text extraction"
```

---

## Task 2: PdfMode enum

**Files:**
- Create: `Classes/Domain/Enum/PdfMode.php`
- Test: `Tests/Unit/Domain/Enum/PdfModeTest.php`

- [ ] **Step 1: Write the failing test `Tests/Unit/Domain/Enum/PdfModeTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\Enum;

use Netresearch\NrRepurpose\Domain\Enum\PdfMode;
use PHPUnit\Framework\TestCase;

final class PdfModeTest extends TestCase
{
    public function testBackedValues(): void
    {
        self::assertSame('auto', PdfMode::Auto->value);
        self::assertSame('text', PdfMode::Text->value);
        self::assertSame('vision', PdfMode::Vision->value);
        self::assertSame('tables', PdfMode::Tables->value);
    }

    public function testFromJobValueDefaultsToAutoForEmptyOrUnknown(): void
    {
        self::assertSame(PdfMode::Auto, PdfMode::fromJobValue(''));
        self::assertSame(PdfMode::Auto, PdfMode::fromJobValue('nonsense'));
        self::assertSame(PdfMode::Vision, PdfMode::fromJobValue('vision'));
    }

    public function testIsAutoOnlyForAuto(): void
    {
        self::assertTrue(PdfMode::Auto->isAuto());
        self::assertFalse(PdfMode::Text->isAuto());
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter PdfModeTest`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Domain\Enum\PdfMode" not found`.

- [ ] **Step 3: Write `Classes/Domain/Enum/PdfMode.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

/**
 * PDF extraction strategy chosen per run. `Auto` staggers the three tiers per page
 * (text -> vision when sparse -> layout when tabular); the others force one tier.
 */
enum PdfMode: string
{
    case Auto = 'auto';
    case Text = 'text';
    case Vision = 'vision';
    case Tables = 'tables';

    /** Lenient mapping from a raw job-row value: empty/unknown falls back to Auto (the spec default). */
    public static function fromJobValue(string $value): self
    {
        return self::tryFrom($value) ?? self::Auto;
    }

    public function isAuto(): bool
    {
        return $this === self::Auto;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter PdfModeTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add Classes/Domain/Enum/PdfMode.php Tests/Unit/Domain/Enum/PdfModeTest.php
git commit -s -m "Add PdfMode enum (auto/text/vision/tables)"
```

---

## Task 3: SourceDocument value object

**Files:**
- Create: `Classes/Domain/ValueObject/SourceDocument.php`
- Test: `Tests/Unit/Domain/ValueObject/SourceDocumentTest.php`

- [ ] **Step 1: Write the failing test `Tests/Unit/Domain/ValueObject/SourceDocumentTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\ValueObject;

use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use PHPUnit\Framework\TestCase;

final class SourceDocumentTest extends TestCase
{
    public function testHoldsAllFieldsWithMetaDefaultingToEmpty(): void
    {
        $doc = new SourceDocument(
            title: 'Annual Report',
            text: 'Body text.',
            sourceLabel: 'https://example.com/report',
            pageCount: 0,
            languageHint: 'en',
        );

        self::assertSame('Annual Report', $doc->title);
        self::assertSame('Body text.', $doc->text);
        self::assertSame('https://example.com/report', $doc->sourceLabel);
        self::assertSame(0, $doc->pageCount);
        self::assertSame('en', $doc->languageHint);
        self::assertSame([], $doc->meta);
    }

    public function testMetaCarriesTierProvenance(): void
    {
        $doc = new SourceDocument(
            title: '',
            text: 'p1',
            sourceLabel: 'doc.pdf',
            pageCount: 1,
            languageHint: '',
            meta: ['tiersUsed' => ['text', 'vision'], 'fetchedVia' => 'chromium'],
        );

        self::assertSame(['text', 'vision'], $doc->meta['tiersUsed']);
        self::assertSame('chromium', $doc->meta['fetchedVia']);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter SourceDocumentTest`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument" not found`.

- [ ] **Step 3: Write `Classes/Domain/ValueObject/SourceDocument.php`** (verbatim contracts signature)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

/**
 * Canonical ingestion result shared by every later pipeline stage. Immutable.
 * Produced by Plan 2 (SourceIngestionService); consumed by Plan 3 (DocumentAnalyzer)
 * and bundled into Plan 3's GenerationContext.
 */
final readonly class SourceDocument
{
    public function __construct(
        public string $title,        // best-effort Titel (kann leer sein)
        public string $text,         // bereinigter Reintext (Readability / PDF-Extraktion)
        public string $sourceLabel,  // URL oder Dateiname, für Anzeige/Logs
        public int $pageCount,       // PDF-Seiten; 0 für Webseiten
        public string $languageHint, // ISO-639-1 best-effort oder '' wenn unbekannt
        /** @var array<string,mixed> */
        public array $meta = [],     // z.B. ['tiersUsed' => ['text','vision'], 'fetchedVia' => 'chromium']
    ) {}
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter SourceDocumentTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add Classes/Domain/ValueObject/SourceDocument.php Tests/Unit/Domain/ValueObject/SourceDocumentTest.php
git commit -s -m "Add SourceDocument value object (ingestion result)"
```

---

## Task 4: PDF source fields (schema, TCA, Job model, BE form)

This adds the `source_pdf` FAL field (auto DB column since v13) and the `pdf_mode` enum column to the Job table, surfaces both in the New form, and exposes them on the Extbase model. The orchestrator/handler are NOT touched.

**Files:**
- Modify: `ext_tables.sql`, `Configuration/TCA/tx_nrrepurpose_domain_model_job.php`, `Classes/Domain/Model/Job.php`, `Resources/Private/Templates/Job/New.html`
- Test: `Tests/Functional/Domain/Repository/JobPdfFieldsTest.php`

- [ ] **Step 1: Write the failing functional test `Tests/Functional/Domain/Repository/JobPdfFieldsTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Domain\Repository;

use Netresearch\NrRepurpose\Domain\Enum\PdfMode;
use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class JobPdfFieldsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-repurpose'];

    public function testPdfModeRoundTripsAndDefaultsToAuto(): void
    {
        $repo = $this->get(JobRepository::class);
        $pm = $this->get(PersistenceManagerInterface::class);

        $job = new Job();
        $job->setSourceTypeEnum(SourceType::PdfUrl);
        $job->setSourceValue('https://example.com/report.pdf');
        $job->setPdfModeEnum(PdfMode::Vision);
        $repo->add($job);
        $pm->persistAll();
        $pm->clearState();

        /** @var Job $loaded */
        $loaded = $repo->findByUid($job->getUid());
        self::assertSame(SourceType::PdfUrl, $loaded->getSourceTypeEnum());
        self::assertSame(PdfMode::Vision, $loaded->getPdfModeEnum());
    }

    public function testDefaultPdfModeIsAuto(): void
    {
        $job = new Job();
        self::assertSame(PdfMode::Auto, $job->getPdfModeEnum());
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter JobPdfFieldsTest"`
Expected: FAIL — `Call to undefined method Netresearch\NrRepurpose\Domain\Model\Job::setPdfModeEnum()` (and the `pdf_mode` column not yet present).

- [ ] **Step 3: Add the `pdf_mode` column to `ext_tables.sql`**

Add this column to the existing `CREATE TABLE tx_nrrepurpose_domain_model_job (...)` block (insert after the `theme` line). The `source_pdf` FAL field needs NO SQL entry — TCA `type=file` auto-creates its DB column since v13 (grounding BE area [11]).

```sql
    pdf_mode varchar(16) DEFAULT 'auto' NOT NULL,
```

The resulting job table block reads (full, for reference — only the `pdf_mode` line is new vs. Plan 1):

```sql
CREATE TABLE tx_nrrepurpose_domain_model_job (
    source_type varchar(16) DEFAULT 'url' NOT NULL,
    source_value text,
    theme varchar(16) DEFAULT 'nr' NOT NULL,
    pdf_mode varchar(16) DEFAULT 'auto' NOT NULL,
    want_podcast smallint unsigned DEFAULT 1 NOT NULL,
    want_schaubild smallint unsigned DEFAULT 1 NOT NULL,
    want_story smallint unsigned DEFAULT 1 NOT NULL,
    status varchar(16) DEFAULT 'queued' NOT NULL,
    progress int unsigned DEFAULT 0 NOT NULL,
    current_step varchar(255) DEFAULT '' NOT NULL,
    error_message text,
    language_detected varchar(16) DEFAULT '' NOT NULL,
    be_user int unsigned DEFAULT 0 NOT NULL,
    artifacts int unsigned DEFAULT 0 NOT NULL
);
```

- [ ] **Step 4: Add `source_pdf` + `pdf_mode` columns to the Job TCA and the form**

In `Configuration/TCA/tx_nrrepurpose_domain_model_job.php`, add two new entries to the `columns` array (insert after the existing `theme` column), and add `pdf_mode, source_pdf` to the `types['0']['showitem']` string.

New `columns` entries:

```php
        'pdf_mode' => [
            'label' => 'PDF extraction mode',
            'displayCond' => 'FIELD:source_type:IN:pdf_url,pdf_fal',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Auto (staggered)', 'value' => 'auto'],
                    ['label' => 'Embedded text only', 'value' => 'text'],
                    ['label' => 'Vision OCR', 'value' => 'vision'],
                    ['label' => 'Layout / tables', 'value' => 'tables'],
                ],
                'default' => 'auto',
            ],
        ],
        'source_pdf' => [
            'label' => 'Source PDF (FAL)',
            'displayCond' => 'FIELD:source_type:=:pdf_fal',
            'config' => [
                'type' => 'file',
                'allowed' => 'pdf',
                'maxitems' => 1,
                'appearance' => [
                    'fileByUrlAllowed' => false,
                ],
            ],
        ],
```

Updated `types` entry (the `pdf_mode, source_pdf` tokens are new):

```php
    'types' => [
        '0' => ['showitem' => 'source_type, source_value, source_pdf, pdf_mode, theme, want_podcast, want_schaubild, want_story, status, progress, current_step, error_message, language_detected, artifacts'],
    ],
```

- [ ] **Step 5: Add `pdfMode` + `sourcePdf` to `Classes/Domain/Model/Job.php`**

Add these property declarations (after the existing `protected string $theme = 'nr';` line):

```php
    protected string $pdfMode = 'auto';

    /** @var \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\TYPO3\CMS\Extbase\Domain\Model\FileReference> */
    protected \TYPO3\CMS\Extbase\Persistence\ObjectStorage $sourcePdf;
```

Initialize `sourcePdf` in the existing constructor (the Plan 1 ctor already news up `$this->artifacts`; add the second line):

```php
    public function __construct()
    {
        $this->artifacts = new ObjectStorage();
        $this->sourcePdf = new ObjectStorage();
    }
```

Add the import and accessors. Add this `use` next to the existing enum imports at the top of the file:

```php
use Netresearch\NrRepurpose\Domain\Enum\PdfMode;
```

Add these methods to the class body (alongside the other accessors):

```php
    public function getPdfMode(): string
    {
        return $this->pdfMode;
    }

    public function setPdfMode(string $pdfMode): void
    {
        $this->pdfMode = $pdfMode;
    }

    public function getPdfModeEnum(): PdfMode
    {
        return PdfMode::fromJobValue($this->pdfMode);
    }

    public function setPdfModeEnum(PdfMode $mode): void
    {
        $this->pdfMode = $mode->value;
    }

    /** @return \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\TYPO3\CMS\Extbase\Domain\Model\FileReference> */
    public function getSourcePdf(): \TYPO3\CMS\Extbase\Persistence\ObjectStorage
    {
        return $this->sourcePdf;
    }

    public function getSourcePdfCount(): int
    {
        return $this->sourcePdf->count();
    }
```

- [ ] **Step 6: Add source-type, PDF mode and PDF upload to the BE New form**

In `Resources/Private/Templates/Job/New.html`, add a source-type selector, a `pdf_mode` selector, and a FAL file field. Replace the single "Source URL" form-group with the block below (keep the rest of the form — theme, the three `want_*` checkboxes, the submit button — unchanged).

```html
        <div class="form-group">
            <label>Source type</label>
            <f:form.select property="sourceType"
                options="{url:'Webpage URL', pdf_url:'PDF URL', pdf_fal:'PDF file (FAL)'}"
                class="form-control" />
        </div>
        <div class="form-group">
            <label>Source URL (for Webpage URL / PDF URL)</label>
            <f:form.textfield property="sourceValue" class="form-control" placeholder="https://…" />
        </div>
        <div class="form-group">
            <label>PDF extraction mode</label>
            <f:form.select property="pdfMode"
                options="{auto:'Auto (staggered)', text:'Embedded text only', vision:'Vision OCR', tables:'Layout / tables'}"
                class="form-control" />
        </div>
        <p class="text-muted">
            For "PDF file (FAL)" upload the PDF as a sys_file first and open the job in the
            list/edit view to attach it; the New form sets type, URL and extraction mode.
        </p>
```

> The plain `<f:form>` cannot render the FAL element (the FormEngine file element only exists inside the TCA/DataHandler edit view — grounding BE area [11]). The New form therefore captures `sourceType`, `sourceValue` and `pdfMode`; attaching an uploaded FAL PDF to `source_pdf` happens through the standard record-edit view rendered from TCA (Step 4). This is the documented v14 split and needs no custom upload handling here.

- [ ] **Step 7: Apply the schema in the DDEV instance and re-run the test**

Run:
```bash
cd /home/sme/p/nr-repurpose/main
ddev exec ".Build/bin/typo3 extension:setup"
ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter JobPdfFieldsTest"
```
Expected: PASS (2 tests). `extension:setup` adds the `pdf_mode` column and the auto-generated `source_pdf` column to the functional schema.

- [ ] **Step 8: Re-run the Plan 1 Job repository test to confirm no regression**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter JobRepositoryTest"`
Expected: PASS (1 test) — the Plan 1 round-trip still works with the new columns.

- [ ] **Step 9: Commit**

```bash
git add ext_tables.sql Configuration/TCA/tx_nrrepurpose_domain_model_job.php Classes/Domain/Model/Job.php Resources/Private/Templates/Job/New.html Tests/Functional/Domain/Repository/JobPdfFieldsTest.php
git commit -s -m "Add PDF source fields (source_pdf FAL, pdf_mode) to job model, TCA and BE form"
```

---

## Task 5: IngestionException

**Files:**
- Create: `Classes/Ingestion/IngestionException.php`
- Test: `Tests/Unit/Ingestion/IngestionExceptionTest.php`

- [ ] **Step 1: Write the failing test `Tests/Unit/Ingestion/IngestionExceptionTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use PHPUnit\Framework\TestCase;

final class IngestionExceptionTest extends TestCase
{
    public function testIsRuntimeExceptionAndCarriesCodeAndPrevious(): void
    {
        $previous = new \RuntimeException('boom');
        $e = new IngestionException('source unreachable', 1749379400, $previous);

        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('source unreachable', $e->getMessage());
        self::assertSame(1749379400, $e->getCode());
        self::assertSame($previous, $e->getPrevious());
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter IngestionExceptionTest`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Ingestion\IngestionException" not found`.

- [ ] **Step 3: Write `Classes/Ingestion/IngestionException.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

/**
 * Thrown when a job source cannot be turned into a SourceDocument: the URL is
 * unreachable, the PDF is empty/encrypted/unreadable, or no usable text could be
 * extracted by any tier. The orchestrator (Plan 3) catches this and marks the job failed.
 */
final class IngestionException extends \RuntimeException {}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter IngestionExceptionTest`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add Classes/Ingestion/IngestionException.php Tests/Unit/Ingestion/IngestionExceptionTest.php
git commit -s -m "Add IngestionException for unreachable/unreadable sources"
```

---

## Task 6: WebPageFetcher (PSR-18 fetch + main-content extraction)

`WebPageFetcher` fetches a URL via the PSR-18 `ClientInterface` (TYPO3 core wires Guzzle for it) and runs a deterministic DOM-stripping main-content extraction. **Justification for the extraction approach:** rather than add a third-party Readability port (e.g. `fivefilters/readability.php`, which pulls `masterminds/html5` and is heavier than this slice needs), Plan 2 uses a deterministic DOM strip: load the HTML with `DOMDocument`, remove boilerplate node types (`script`, `style`, `nav`, `header`, `footer`, `aside`, `form`, `noscript`, comments), prefer the densest of `<article>`/`<main>`/`<body>`, then collapse whitespace. This is reproducible (no scoring randomness), dependency-free, and good enough for the MVP's "navigation/boilerplate out" requirement (spec §7). A Chromium pre-render for JS-heavy pages is the Plan-4 path (`HtmlToImageRendererInterface` infra) — Plan 2 keeps a **static-HTML default** and records `meta['fetchedVia']='static'`; the hook for `'chromium'` is left as a documented seam (no code) so Plan 4 can supply rendered HTML without changing this class's contract.

**Files:**
- Create: `Classes/Ingestion/WebPageFetcher.php`
- Test: `Tests/Unit/Ingestion/WebPageFetcherTest.php`, `Tests/Fixtures/Web/article.html`

- [ ] **Step 1: Write the HTML fixture `Tests/Fixtures/Web/article.html`**

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Quarterly Results 2026</title>
    <style>.nav{display:none}</style>
    <script>console.log('tracking');</script>
</head>
<body>
    <header><nav>Home About Contact</nav></header>
    <article>
        <h1>Quarterly Results 2026</h1>
        <p>Revenue grew by 12 percent to 48 million euro in the first quarter.</p>
        <p>The board confirmed the dividend of 1.20 euro per share.</p>
    </article>
    <aside><p>Subscribe to our newsletter for cookies and offers.</p></aside>
    <footer><p>Copyright 2026 Example Corp. All rights reserved.</p></footer>
</body>
</html>
```

- [ ] **Step 2: Write the failing test `Tests/Unit/Ingestion/WebPageFetcherTest.php`** (fake PSR-18 client; no network)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class WebPageFetcherTest extends TestCase
{
    private function client(int $status, string $body): ClientInterface
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse($status)
            ->withBody($factory->createStream($body));

        return new class($response) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    public function testExtractsTitleAndMainContentDroppingBoilerplate(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/Web/article.html');
        $factory = new Psr17Factory();
        $fetcher = new WebPageFetcher($this->client(200, $html), $factory);

        $doc = $fetcher->fetch('https://example.com/q1');

        self::assertSame('Quarterly Results 2026', $doc->title);
        self::assertStringContainsString('Revenue grew by 12 percent', $doc->text);
        self::assertStringContainsString('dividend of 1.20 euro', $doc->text);
        self::assertStringNotContainsString('tracking', $doc->text);     // <script> removed
        self::assertStringNotContainsString('Home About Contact', $doc->text); // <nav> removed
        self::assertStringNotContainsString('newsletter', $doc->text);   // <aside> removed
        self::assertStringNotContainsString('All rights reserved', $doc->text); // <footer> removed
        self::assertSame(0, $doc->pageCount);
        self::assertSame('static', $doc->meta['fetchedVia']);
        self::assertSame('https://example.com/q1', $doc->sourceLabel);
    }

    public function testThrowsIngestionExceptionOnNon2xx(): void
    {
        $factory = new Psr17Factory();
        $fetcher = new WebPageFetcher($this->client(404, 'Not found'), $factory);

        $this->expectException(IngestionException::class);
        $fetcher->fetch('https://example.com/missing');
    }

    public function testThrowsIngestionExceptionOnEmptyBody(): void
    {
        $factory = new Psr17Factory();
        $fetcher = new WebPageFetcher($this->client(200, '   '), $factory);

        $this->expectException(IngestionException::class);
        $fetcher->fetch('https://example.com/empty');
    }
}
```

> `nyholm/psr7` and `psr/http-client` are present transitively via `typo3/cms-core` (Guzzle PSR-18 + nyholm factories ship with core); if `Nyholm\Psr7\Factory\Psr17Factory` is not autoloadable in the unit context, swap it for `TYPO3\CMS\Core\Http\ResponseFactory`/`RequestFactory` from `typo3/cms-core`, which are always available. The test asserts behavior, not the concrete factory class.

- [ ] **Step 3: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter WebPageFetcherTest`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Ingestion\WebPageFetcher" not found`.

- [ ] **Step 4: Write `Classes/Ingestion/WebPageFetcher.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Fetches a webpage via PSR-18 and extracts its main textual content with a deterministic
 * DOM strip (boilerplate node types removed, densest content container kept). JS-heavy
 * pages would be pre-rendered with the Plan-4 Chromium path; Plan 2 defaults to static HTML.
 */
final class WebPageFetcher
{
    /** Node names removed wholesale before text extraction. */
    private const BOILERPLATE_TAGS = ['script', 'style', 'nav', 'header', 'footer', 'aside', 'form', 'noscript', 'svg'];

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {}

    public function fetch(string $url): SourceDocument
    {
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('User-Agent', 'nr_repurpose/0.1 (+https://www.netresearch.de)')
            ->withHeader('Accept', 'text/html,application/xhtml+xml');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new IngestionException('URL not reachable: ' . $url, 1749379410, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new IngestionException(sprintf('URL returned HTTP %d: %s', $status, $url), 1749379411);
        }

        $html = (string) $response->getBody();
        if (trim($html) === '') {
            throw new IngestionException('URL returned an empty body: ' . $url, 1749379412);
        }

        $title = $this->extractTitle($html);
        $text = $this->extractMainText($html);

        if ($text === '') {
            throw new IngestionException('No readable content extracted from: ' . $url, 1749379413);
        }

        return new SourceDocument(
            title: $title,
            text: $text,
            sourceLabel: $url,
            pageCount: 0,
            languageHint: $this->detectLanguageHint($html),
            meta: ['fetchedVia' => 'static'],
        );
    }

    private function loadDom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Force UTF-8 interpretation regardless of a missing/late <meta charset>.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    private function extractTitle(string $html): string
    {
        $dom = $this->loadDom($html);
        $titles = $dom->getElementsByTagName('title');
        if ($titles->length > 0) {
            return trim((string) $titles->item(0)?->textContent);
        }
        $h1 = $dom->getElementsByTagName('h1');

        return $h1->length > 0 ? trim((string) $h1->item(0)?->textContent) : '';
    }

    private function detectLanguageHint(string $html): string
    {
        if (preg_match('/<html[^>]*\blang=["\']([a-zA-Z-]{2,})["\']/', $html, $m) === 1) {
            return strtolower(substr($m[1], 0, 2));
        }

        return '';
    }

    private function extractMainText(string $html): string
    {
        $dom = $this->loadDom($html);

        // 1) Drop boilerplate node types.
        foreach (self::BOILERPLATE_TAGS as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            // Snapshot to a static array — removing during a live NodeList iteration skips siblings.
            $toRemove = [];
            foreach ($nodes as $node) {
                $toRemove[] = $node;
            }
            foreach ($toRemove as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // 2) Drop HTML comments.
        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//comment()') ?: []) as $comment) {
            $comment->parentNode?->removeChild($comment);
        }

        // 3) Prefer the densest of <article>/<main>, else <body>.
        $candidate = $this->densestNode($dom, ['article', 'main']) ?? $dom->getElementsByTagName('body')->item(0);
        $raw = $candidate !== null ? (string) $candidate->textContent : (string) $dom->textContent;

        return $this->collapseWhitespace($raw);
    }

    private function densestNode(\DOMDocument $dom, array $tagNames): ?\DOMNode
    {
        $best = null;
        $bestLength = 0;
        foreach ($tagNames as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $length = strlen(trim((string) $node->textContent));
                if ($length > $bestLength) {
                    $bestLength = $length;
                    $best = $node;
                }
            }
        }

        return $best;
    }

    private function collapseWhitespace(string $text): string
    {
        // Normalise newlines, collapse runs of blank lines and intra-line whitespace.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\h*\R\h*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter WebPageFetcherTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add Classes/Ingestion/WebPageFetcher.php Tests/Unit/Ingestion/WebPageFetcherTest.php Tests/Fixtures/Web/article.html
git commit -s -m "Add WebPageFetcher (PSR-18 fetch + deterministic main-content extraction)"
```

---

## Task 7: PdfTextExtractor (smalot per-page + sparse detection)

**Files:**
- Create: `Classes/Ingestion/PdfTextExtractor.php`
- Test: `Tests/Unit/Ingestion/PdfTextExtractorTest.php`, `Tests/Fixtures/Pdf/sample-text.pdf`

- [ ] **Step 1: Generate the tiny real text PDF fixture `Tests/Fixtures/Pdf/sample-text.pdf`**

A 1-page PDF with selectable embedded text is needed (no scan, no encryption). Generate it deterministically with a tiny hand-written PDF (valid, parseable by smalot, ~1 KB) so no external tool is required:

Run:
```bash
cd /home/sme/p/nr-repurpose/main
mkdir -p Tests/Fixtures/Pdf
cat > /tmp/make_pdf.php <<'PHP'
<?php
$content = "BT /F1 18 Tf 72 720 Td (Repurpose ingestion sample document.) Tj T* (Net revenue rose to 48 million euro.) Tj ET";
$objs = [];
$objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
$objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
$objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>";
$objs[4] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
$objs[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
$pdf = "%PDF-1.4\n";
$offsets = [];
foreach ($objs as $n => $body) {
    $offsets[$n] = strlen($pdf);
    $pdf .= "$n 0 obj\n$body\nendobj\n";
}
$xrefPos = strlen($pdf);
$pdf .= "xref\n0 " . (count($objs) + 1) . "\n";
$pdf .= "0000000000 65535 f \n";
foreach ($offsets as $off) {
    $pdf .= sprintf("%010d 00000 n \n", $off);
}
$pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";
file_put_contents('Tests/Fixtures/Pdf/sample-text.pdf', $pdf);
echo "wrote " . strlen($pdf) . " bytes\n";
PHP
php /tmp/make_pdf.php && rm -f /tmp/make_pdf.php
```
Expected: prints `wrote <N> bytes` and creates `Tests/Fixtures/Pdf/sample-text.pdf`.

Verify smalot can read it:
Run: `cd /home/sme/p/nr-repurpose/main && php -r 'require ".Build/vendor/autoload.php"; $p=new Smalot\PdfParser\Parser(); echo trim($p->parseFile("Tests/Fixtures/Pdf/sample-text.pdf")->getText());'`
Expected: prints text containing `Repurpose ingestion sample document.` and `Net revenue rose to 48 million euro.`

- [ ] **Step 2: Write the failing test `Tests/Unit/Ingestion/PdfTextExtractorTest.php`** (real fixture, no provider)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfTextExtractor;
use PHPUnit\Framework\TestCase;

final class PdfTextExtractorTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = __DIR__ . '/../../Fixtures/Pdf/sample-text.pdf';
    }

    public function testExtractsPerPageTextAndMarksDenseTextNotSparse(): void
    {
        $pages = (new PdfTextExtractor())->extract($this->fixture);

        self::assertCount(1, $pages);
        self::assertSame(1, $pages[0]['page']);
        self::assertStringContainsString('Net revenue rose to 48 million euro', $pages[0]['text']);
        self::assertFalse($pages[0]['isSparse']);
    }

    public function testThrowsIngestionExceptionForMissingFile(): void
    {
        $this->expectException(IngestionException::class);
        (new PdfTextExtractor())->extract('/no/such/file.pdf');
    }
}
```

- [ ] **Step 3: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter PdfTextExtractorTest`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Ingestion\PdfTextExtractor" not found`.

- [ ] **Step 4: Write `Classes/Ingestion/PdfTextExtractor.php`** (grounding Tier-1 snippet, adapted to IngestionException)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * Tier 1 — embedded PDF text via smalot/pdfparser, per page, with near-empty (sparse)
 * detection so the auto dispatcher can escalate scanned pages to Vision OCR.
 */
final class PdfTextExtractor
{
    /** Minimum non-whitespace chars before a page counts as "has text". */
    private const MIN_CHARS_PER_PAGE = 80;

    /**
     * @return list<array{page:int, text:string, isSparse:bool}>
     * @throws IngestionException on a missing, encrypted or unparseable PDF
     */
    public function extract(string $absPath): array
    {
        if (!is_file($absPath)) {
            throw new IngestionException('PDF file not found: ' . $absPath, 1749379420);
        }

        $config = new Config();
        // Mitigates known false-positive "Secured pdf file" detection (smalot issues #488/#743).
        $config->setIgnoreEncryption(true);

        $parser = new Parser([], $config);
        try {
            $document = $parser->parseFile($absPath);
            $pageObjects = $document->getPages();
        } catch (\Throwable $e) {
            // smalot throws \Exception('Secured pdf file are currently not supported.') on real encryption.
            throw new IngestionException(
                'PDF could not be parsed (possibly encrypted): ' . $e->getMessage(),
                1749379421,
                $e,
            );
        }

        $pages = [];
        foreach ($pageObjects as $i => $page) {
            $text = trim($page->getText());
            $density = strlen(preg_replace('/\s+/', '', $text) ?? '');
            $pages[] = [
                'page' => $i + 1,
                'text' => $text,
                'isSparse' => $density < self::MIN_CHARS_PER_PAGE,
            ];
        }

        if ($pages === []) {
            throw new IngestionException('PDF has no pages: ' . $absPath, 1749379422);
        }

        return $pages;
    }
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter PdfTextExtractorTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add Classes/Ingestion/PdfTextExtractor.php Tests/Unit/Ingestion/PdfTextExtractorTest.php Tests/Fixtures/Pdf/sample-text.pdf
git commit -s -m "Add PdfTextExtractor (smalot per-page text + sparse detection)"
```

---

## Task 8: Poppler runner seam

Both `PdfVisionExtractor` (needs `pdftoppm`) and `PdfLayoutExtractor` (needs `pdftotext -layout`) shell out to Poppler. To keep them unit-testable, the actual `Symfony\Component\Process\Process` calls live behind `PopplerRunnerInterface`; unit tests fake it, and one functional test exercises the real binary.

**Files:**
- Create: `Classes/Ingestion/Poppler/PopplerRunnerInterface.php`, `Classes/Ingestion/Poppler/SymfonyProcessPopplerRunner.php`
- Test: `Tests/Functional/Ingestion/SymfonyProcessPopplerRunnerTest.php`

- [ ] **Step 1: Write `Classes/Ingestion/Poppler/PopplerRunnerInterface.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion\Poppler;

/**
 * Seam over the Poppler CLI binaries (pdftoppm, pdftotext). Real implementation uses
 * Symfony Process; unit tests fake it so PdfVisionExtractor/PdfLayoutExtractor stay pure.
 */
interface PopplerRunnerInterface
{
    /**
     * Rasterize one 1-based page of $absPdfPath to PNG and return the raw PNG bytes.
     * (pdftoppm -png -r <dpi> -f <page> -l <page> -singlefile)
     */
    public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string;

    /**
     * Extract one 1-based page preserving columns/tables as plain text.
     * (pdftotext -layout -f <page> -l <page> -enc UTF-8 -nopgbrk -q ... -)
     */
    public function extractLayout(string $absPdfPath, int $page): string;
}
```

- [ ] **Step 2: Write `Classes/Ingestion/Poppler/SymfonyProcessPopplerRunner.php`** (grounding Tier-2/Tier-3 snippets)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion\Poppler;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Real Poppler invocations via Symfony Process. Binaries (pdftoppm/pdftotext) are baked into
 * the DDEV web image in Plan 1 Task 2 (poppler-utils). No Ghostscript needed (Poppler renders natively).
 */
final class SymfonyProcessPopplerRunner implements PopplerRunnerInterface
{
    private const PROCESS_TIMEOUT = 120.0;

    public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string
    {
        $tmpPrefix = sys_get_temp_dir() . '/nrrepurpose_' . bin2hex(random_bytes(6));
        // -singlefile => output is exactly <prefix>.png (no -NN page suffix).
        $process = new Process([
            'pdftoppm', '-png',
            '-r', (string) $dpi,
            '-f', (string) $page,
            '-l', (string) $page,
            '-singlefile',
            $absPdfPath, $tmpPrefix,
        ]);
        $process->setTimeout(self::PROCESS_TIMEOUT);

        $pngPath = $tmpPrefix . '.png';
        try {
            $process->mustRun();
            $bytes = file_get_contents($pngPath);
            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException('pdftoppm produced no PNG for page ' . $page, 1749379430);
            }

            return $bytes;
        } catch (ProcessFailedException $e) {
            throw new \RuntimeException('pdftoppm failed for page ' . $page . ': ' . $e->getMessage(), 1749379431, $e);
        } finally {
            if (is_file($pngPath)) {
                @unlink($pngPath);
            }
        }
    }

    public function extractLayout(string $absPdfPath, int $page): string
    {
        // '-' writes UTF-8 layout-preserved text to stdout.
        $process = new Process([
            'pdftotext', '-layout',
            '-f', (string) $page,
            '-l', (string) $page,
            '-enc', 'UTF-8',
            '-nopgbrk',
            '-q',
            $absPdfPath, '-',
        ]);
        $process->setTimeout(self::PROCESS_TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new \RuntimeException('pdftotext -layout failed for page ' . $page . ': ' . $e->getMessage(), 1749379432, $e);
        }

        return rtrim($process->getOutput());
    }
}
```

- [ ] **Step 3: Write the functional test `Tests/Functional/Ingestion/SymfonyProcessPopplerRunnerTest.php`** (real Poppler binary)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Ingestion;

use Netresearch\NrRepurpose\Ingestion\Poppler\SymfonyProcessPopplerRunner;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SymfonyProcessPopplerRunnerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-repurpose'];

    private function fixture(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Pdf/sample-text.pdf';
    }

    public function testRasterizePageReturnsPngBytes(): void
    {
        $bytes = (new SymfonyProcessPopplerRunner())->rasterizePage($this->fixture(), 1, 100);

        // PNG magic number.
        self::assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8));
    }

    public function testExtractLayoutReturnsText(): void
    {
        $text = (new SymfonyProcessPopplerRunner())->extractLayout($this->fixture(), 1);

        self::assertStringContainsString('Net revenue rose to 48 million euro', $text);
    }
}
```

- [ ] **Step 4: Run the functional test (real Poppler), verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter SymfonyProcessPopplerRunnerTest"`
Expected: PASS (2 tests). Requires the DDEV web image's `poppler-utils` (Plan 1 Task 2). Runs inside `ddev exec` so the binaries are on `PATH`.

- [ ] **Step 5: Commit**

```bash
git add Classes/Ingestion/Poppler Tests/Functional/Ingestion/SymfonyProcessPopplerRunnerTest.php
git commit -s -m "Add Poppler runner seam (pdftoppm rasterize, pdftotext layout)"
```

---

## Task 9: PdfVisionExtractor (pdftoppm → data URI → nr-llm Vision)

`PdfVisionExtractor` rasterizes a page via the Poppler runner, builds a `data:image/png;base64,...` URI, and OCRs it through nr-llm `VisionServiceInterface::analyzeImage`. Both seams are faked in the unit test; the real Vision call is reserved for the orchestrator-level smoke run (Plan 3+).

**Files:**
- Create: `Classes/Ingestion/PdfVisionExtractor.php`
- Test: `Tests/Unit/Ingestion/PdfVisionExtractorTest.php`

- [ ] **Step 1: Write the failing test `Tests/Unit/Ingestion/PdfVisionExtractorTest.php`** (fake runner + fake VisionService)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrRepurpose\Ingestion\PdfVisionExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;
use PHPUnit\Framework\TestCase;

final class PdfVisionExtractorTest extends TestCase
{
    public function testOcrsRasterizedPageAndPassesDataUriToVision(): void
    {
        $runner = new class implements PopplerRunnerInterface {
            public string $lastPdf = '';
            public int $lastPage = 0;
            public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string
            {
                $this->lastPdf = $absPdfPath;
                $this->lastPage = $page;
                return "\x89PNG\r\n\x1a\nFAKEPNGBYTES";
            }
            public function extractLayout(string $absPdfPath, int $page): string
            {
                return '';
            }
        };

        $vision = new class implements VisionServiceInterface {
            public string $receivedImageUrl = '';
            public string $receivedPrompt = '';
            public function generateAltText(string|array $imageUrl, ?VisionOptions $options = null): string|array { return ''; }
            public function generateTitle(string|array $imageUrl, ?VisionOptions $options = null): string|array { return ''; }
            public function generateDescription(string|array $imageUrl, ?VisionOptions $options = null): string|array { return ''; }
            public function analyzeImage(string|array $imageUrl, string $customPrompt, ?VisionOptions $options = null): string|array
            {
                $this->receivedImageUrl = (string) $imageUrl;
                $this->receivedPrompt = $customPrompt;
                return "Net revenue rose to 48 million euro.";
            }
        };

        $extractor = new PdfVisionExtractor($runner, $vision);
        $text = $extractor->ocrPage('/abs/doc.pdf', 2, beUser: 7);

        self::assertSame('/abs/doc.pdf', $runner->lastPdf);
        self::assertSame(2, $runner->lastPage);
        self::assertStringStartsWith('data:image/png;base64,', $vision->receivedImageUrl);
        self::assertSame(
            base64_encode("\x89PNG\r\n\x1a\nFAKEPNGBYTES"),
            substr($vision->receivedImageUrl, strlen('data:image/png;base64,')),
        );
        self::assertStringContainsString('48 million euro', $text);
    }

    public function testJoinsArrayVisionResult(): void
    {
        $runner = new class implements PopplerRunnerInterface {
            public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string { return 'PNG'; }
            public function extractLayout(string $absPdfPath, int $page): string { return ''; }
        };
        $vision = new class implements VisionServiceInterface {
            public function generateAltText(string|array $imageUrl, ?VisionOptions $options = null): string|array { return ''; }
            public function generateTitle(string|array $imageUrl, ?VisionOptions $options = null): string|array { return ''; }
            public function generateDescription(string|array $imageUrl, ?VisionOptions $options = null): string|array { return ''; }
            public function analyzeImage(string|array $imageUrl, string $customPrompt, ?VisionOptions $options = null): string|array
            {
                return ['line one', 'line two'];
            }
        };

        $text = (new PdfVisionExtractor($runner, $vision))->ocrPage('/abs/doc.pdf', 1, beUser: 0);

        self::assertSame("line one\nline two", $text);
    }
}
```

> The fake implements the full `VisionServiceInterface` (verified methods: `generateAltText`/`generateTitle`/`generateDescription`/`analyzeImage` — grounding nr-llm [7], `VisionServiceInterface.php:28-80`). If the interface signature differs at implementation time, regenerate the anonymous class to match it exactly; the asserted behavior (data-URI prefix, page routing, array-join) is the contract under test.

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter PdfVisionExtractorTest`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Ingestion\PdfVisionExtractor" not found`.

- [ ] **Step 3: Write `Classes/Ingestion/PdfVisionExtractor.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;

/**
 * Tier 2 — renders a PDF page to PNG (Poppler) and OCRs it through nr-llm Vision.
 * Used by the auto dispatcher for scanned/image-only pages and by forced `vision` mode.
 */
final class PdfVisionExtractor
{
    private const OCR_PROMPT = 'Transcribe ALL text in this page image verbatim, '
        . 'preserving reading order and line breaks. Output plain text only, no commentary.';

    private const OCR_MAX_TOKENS = 2000;

    public function __construct(
        private readonly PopplerRunnerInterface $poppler,
        private readonly VisionServiceInterface $vision,
    ) {}

    /**
     * OCR a single 1-based page of $absPdfPath. $beUser>0 enables the nr-llm budget guard
     * on the Vision call; pass 0 to skip (CLI/anonymous).
     */
    public function ocrPage(string $absPdfPath, int $page, int $beUser, int $dpi = 200): string
    {
        $png = $this->poppler->rasterizePage($absPdfPath, $page, $dpi);
        $dataUri = 'data:image/png;base64,' . base64_encode($png);

        $options = (new VisionOptions())->withMaxTokens(self::OCR_MAX_TOKENS);
        if ($beUser > 0) {
            $options = $options->withBeUserUid($beUser);
        }

        // VisionService::analyzeImage() validates data:image/png;base64,... URIs natively.
        $result = $this->vision->analyzeImage($dataUri, self::OCR_PROMPT, $options);

        return is_array($result) ? implode("\n", $result) : $result;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter PdfVisionExtractorTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add Classes/Ingestion/PdfVisionExtractor.php Tests/Unit/Ingestion/PdfVisionExtractorTest.php
git commit -s -m "Add PdfVisionExtractor (pdftoppm + nr-llm Vision OCR)"
```

---

## Task 10: PdfLayoutExtractor (pdftotext -layout)

**Files:**
- Create: `Classes/Ingestion/PdfLayoutExtractor.php`
- Test: `Tests/Unit/Ingestion/PdfLayoutExtractorTest.php`

- [ ] **Step 1: Write the failing test `Tests/Unit/Ingestion/PdfLayoutExtractorTest.php`** (fake runner)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrRepurpose\Ingestion\PdfLayoutExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;
use PHPUnit\Framework\TestCase;

final class PdfLayoutExtractorTest extends TestCase
{
    public function testDelegatesToRunnerLayoutExtraction(): void
    {
        $runner = new class implements PopplerRunnerInterface {
            public int $lastPage = 0;
            public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string { return ''; }
            public function extractLayout(string $absPdfPath, int $page): string
            {
                $this->lastPage = $page;
                return "Region    Q1     Q2\nNorth       10     14";
            }
        };

        $text = (new PdfLayoutExtractor($runner))->extractPage('/abs/doc.pdf', 3);

        self::assertSame(3, $runner->lastPage);
        self::assertStringContainsString('Region    Q1', $text);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter PdfLayoutExtractorTest`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Ingestion\PdfLayoutExtractor" not found`.

- [ ] **Step 3: Write `Classes/Ingestion/PdfLayoutExtractor.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;

/**
 * Tier 3 — layout/table-aware extraction via `pdftotext -layout`. Used by the auto
 * dispatcher for pages that look tabular and by forced `tables` mode.
 */
final class PdfLayoutExtractor
{
    public function __construct(private readonly PopplerRunnerInterface $poppler) {}

    /** Extract one 1-based page preserving columns/tables. */
    public function extractPage(string $absPdfPath, int $page): string
    {
        return $this->poppler->extractLayout($absPdfPath, $page);
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter PdfLayoutExtractorTest`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add Classes/Ingestion/PdfLayoutExtractor.php Tests/Unit/Ingestion/PdfLayoutExtractorTest.php
git commit -s -m "Add PdfLayoutExtractor (pdftotext -layout)"
```

---

## Task 11: PdfFileResolver (row → absolute local PDF path)

For `pdf_url` the PDF must be downloaded to a temp file; for `pdf_fal` the attached `sys_file` must be resolved to a local path (FAL local-driver files have one; remote drivers are copied to a temp file). This keeps `SourceIngestionService` free of FAL/HTTP details.

**Files:**
- Create: `Classes/Ingestion/PdfFileResolver.php`
- Test: `Tests/Functional/Ingestion/PdfFileResolverTest.php`

- [ ] **Step 1: Write the failing functional test `Tests/Functional/Ingestion/PdfFileResolverTest.php`** (FAL via testing-framework)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Ingestion;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PdfFileResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-repurpose'];

    public function testResolvesFalAttachedPdfToReadableLocalPath(): void
    {
        // Place the fixture PDF into the default FAL storage and resolve via source_file uid.
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $bytes = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Pdf/sample-text.pdf');
        $folder = $storage->hasFolder('repurpose') ? $storage->getFolder('repurpose') : $storage->createFolder('repurpose');
        $file = $storage->createFile('resolver-test.pdf', $folder);
        $file->setContents($bytes);

        $resolver = $this->get(PdfFileResolver::class);
        $path = $resolver->resolve(['source_type' => 'pdf_fal', 'source_pdf' => $file->getUid()]);

        self::assertFileExists($path);
        self::assertSame($bytes, (string) file_get_contents($path));
    }

    public function testThrowsForFalSourceWithoutFile(): void
    {
        $resolver = $this->get(PdfFileResolver::class);
        $this->expectException(IngestionException::class);
        $resolver->resolve(['source_type' => 'pdf_fal', 'source_pdf' => 0]);
    }
}
```

> The `pdf_fal` row stores the `sys_file` uid in `source_pdf` (TCA type=file persists the relation; for a single attached file the resolver reads the referenced `sys_file` uid). If the functional harness exposes it as a `sys_file_reference` instead of a direct uid, adjust `resolve()` to read the reference's `uid_local` — the asserted behavior (bytes round-trip to a local path) is the contract. The `pdf_url` download branch is covered indirectly by the Task 12 service test using a faked downloader; here we test the FAL branch against a real storage.

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter PdfFileResolverTest"`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Ingestion\PdfFileResolver" not found`.

- [ ] **Step 3: Write `Classes/Ingestion/PdfFileResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Resolves a job row to an absolute, locally readable PDF path:
 *  - pdf_fal: the attached sys_file is fetched for local processing (ResourceFactory).
 *  - pdf_url: the remote PDF is downloaded to a temp file (PSR-18).
 */
final class PdfFileResolver
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {}

    /** @param array<string,mixed> $jobRow */
    public function resolve(array $jobRow): string
    {
        $type = (string) ($jobRow['source_type'] ?? '');

        return match ($type) {
            'pdf_fal' => $this->resolveFalFile($jobRow),
            'pdf_url' => $this->downloadUrl((string) ($jobRow['source_value'] ?? '')),
            default => throw new IngestionException('PdfFileResolver does not handle source_type: ' . $type, 1749379440),
        };
    }

    /** @param array<string,mixed> $jobRow */
    private function resolveFalFile(array $jobRow): string
    {
        $fileUid = (int) ($jobRow['source_pdf'] ?? 0);
        if ($fileUid <= 0) {
            throw new IngestionException('pdf_fal job has no attached PDF (source_pdf empty)', 1749379441);
        }

        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (FileDoesNotExistException $e) {
            throw new IngestionException('Attached PDF sys_file not found: ' . $fileUid, 1749379442, $e);
        }

        // Local driver returns the real path; remote drivers copy to a temp file.
        $localPath = $file->getForLocalProcessing(false);
        if (!is_file($localPath)) {
            throw new IngestionException('Could not access attached PDF locally: ' . $fileUid, 1749379443);
        }

        return $localPath;
    }

    private function downloadUrl(string $url): string
    {
        if ($url === '') {
            throw new IngestionException('pdf_url job has an empty source_value', 1749379444);
        }

        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('User-Agent', 'nr_repurpose/0.1 (+https://www.netresearch.de)')
            ->withHeader('Accept', 'application/pdf');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new IngestionException('PDF URL not reachable: ' . $url, 1749379445, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new IngestionException(sprintf('PDF URL returned HTTP %d: %s', $status, $url), 1749379446);
        }

        $bytes = (string) $response->getBody();
        if ($bytes === '') {
            throw new IngestionException('PDF URL returned an empty body: ' . $url, 1749379447);
        }

        $tmp = sys_get_temp_dir() . '/nrrepurpose_dl_' . bin2hex(random_bytes(6)) . '.pdf';
        if (file_put_contents($tmp, $bytes) === false) {
            throw new IngestionException('Could not write downloaded PDF to temp file', 1749379448);
        }

        return $tmp;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter PdfFileResolverTest"`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add Classes/Ingestion/PdfFileResolver.php Tests/Functional/Ingestion/PdfFileResolverTest.php
git commit -s -m "Add PdfFileResolver (FAL/URL PDF to local path)"
```

---

## Task 12: SourceIngestionService (interface + per-page auto dispatcher)

The public entry point. `ingest(array $jobRow): SourceDocument` selects by `source_type`: `url` → `WebPageFetcher`; `pdf_url`/`pdf_fal` → resolve to a local PDF, run the per-page tier dispatcher honoring `pdf_mode`, assemble a `SourceDocument`. The dispatcher mirrors the grounding doc: `auto` runs Tier 1 then escalates sparse pages to Vision and tabular pages to Layout; `text`/`vision`/`tables` force one tier for every page.

**Files:**
- Create: `Classes/Ingestion/SourceIngestionServiceInterface.php`, `Classes/Ingestion/SourceIngestionService.php`
- Test: `Tests/Unit/Ingestion/SourceIngestionServiceTest.php`, `Tests/Functional/Ingestion/SourceIngestionServiceTest.php`

- [ ] **Step 1: Write `Classes/Ingestion/SourceIngestionServiceInterface.php`** (verbatim contracts signature)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;

interface SourceIngestionServiceInterface
{
    /**
     * @param array<string,mixed> $jobRow
     * @throws IngestionException bei nicht erreichbarer/unlesbarer Quelle
     */
    public function ingest(array $jobRow): SourceDocument;
}
```

- [ ] **Step 2: Write the failing unit test `Tests/Unit/Ingestion/SourceIngestionServiceTest.php`** (all seams faked)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\PdfLayoutExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfTextExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfVisionExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionService;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use PHPUnit\Framework\TestCase;

final class SourceIngestionServiceTest extends TestCase
{
    /**
     * Builds a service whose PDF text tier returns fixed per-page descriptors, and whose
     * vision/layout tiers return tagged strings so the dispatcher routing is observable.
     *
     * @param list<array{page:int,text:string,isSparse:bool}> $textPages
     */
    private function service(array $textPages): SourceIngestionService
    {
        $text = new class($textPages) extends PdfTextExtractor {
            /** @param list<array{page:int,text:string,isSparse:bool}> $pages */
            public function __construct(private readonly array $pages) {}
            public function extract(string $absPath): array
            {
                return $this->pages;
            }
        };

        $runner = new class implements PopplerRunnerInterface {
            public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string { return 'PNG'; }
            public function extractLayout(string $absPdfPath, int $page): string { return 'LAYOUT-P' . $page; }
        };

        $vision = new class($runner) extends PdfVisionExtractor {
            public function ocrPage(string $absPdfPath, int $page, int $beUser, int $dpi = 200): string
            {
                return 'VISION-P' . $page;
            }
        };
        $layout = new PdfLayoutExtractor($runner);

        $fetcher = new class extends WebPageFetcher {
            public function __construct() {}
        };

        $resolver = new class extends PdfFileResolver {
            public function __construct() {}
            public function resolve(array $jobRow): string { return '/abs/doc.pdf'; }
        };

        return new SourceIngestionService($fetcher, $resolver, $text, $vision, $layout);
    }

    public function testAutoModeRoutesEachPageByDensityAndTabularity(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => 'Plenty of dense narrative text on this first page.', 'isSparse' => false],
            ['page' => 2, 'text' => '', 'isSparse' => true],                                  // -> vision
            ['page' => 3, 'text' => "Region   Q1   Q2\nNorth     10   14\nSouth     8    9", 'isSparse' => false], // -> layout
        ]);

        $doc = $service->ingest([
            'uid' => 5, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'auto', 'be_user' => 0,
        ]);

        self::assertStringContainsString('dense narrative text', $doc->text); // tier 1
        self::assertStringContainsString('VISION-P2', $doc->text);            // tier 2
        self::assertStringContainsString('LAYOUT-P3', $doc->text);            // tier 3
        self::assertSame(3, $doc->pageCount);
        self::assertSame(['text', 'vision', 'tables'], $doc->meta['tiersUsed']);
        self::assertSame('doc.pdf', $doc->sourceLabel);
    }

    public function testForcedVisionModeOcrsEveryPage(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => 'dense', 'isSparse' => false],
            ['page' => 2, 'text' => 'dense', 'isSparse' => false],
        ]);

        $doc = $service->ingest([
            'uid' => 6, 'source_type' => 'pdf_url', 'source_value' => 'https://example.com/x.pdf',
            'pdf_mode' => 'vision', 'be_user' => 0,
        ]);

        self::assertSame("VISION-P1\n\nVISION-P2", $doc->text);
        self::assertSame(['vision'], $doc->meta['tiersUsed']);
    }

    public function testForcedTablesModeUsesLayoutForEveryPage(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => 'dense', 'isSparse' => false],
        ]);

        $doc = $service->ingest([
            'uid' => 7, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'tables', 'be_user' => 0,
        ]);

        self::assertSame('LAYOUT-P1', $doc->text);
        self::assertSame(['tables'], $doc->meta['tiersUsed']);
    }

    public function testForcedTextModeKeepsEmbeddedTextEvenWhenSparse(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => 'thin', 'isSparse' => true],
        ]);

        $doc = $service->ingest([
            'uid' => 8, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'text', 'be_user' => 0,
        ]);

        self::assertSame('thin', $doc->text);
        self::assertSame(['text'], $doc->meta['tiersUsed']);
    }

    public function testThrowsWhenNoTextCouldBeExtracted(): void
    {
        $service = $this->service([
            ['page' => 1, 'text' => '', 'isSparse' => false],
        ]);

        $this->expectException(IngestionException::class);
        $service->ingest([
            'uid' => 9, 'source_type' => 'pdf_fal', 'source_pdf' => 1, 'pdf_mode' => 'text', 'be_user' => 0,
        ]);
    }
}
```

- [ ] **Step 3: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter SourceIngestionServiceTest`
Expected: FAIL — `Class "Netresearch\NrRepurpose\Ingestion\SourceIngestionService" not found`.

- [ ] **Step 4: Write `Classes/Ingestion/SourceIngestionService.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Netresearch\NrRepurpose\Domain\Enum\PdfMode;
use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;

/**
 * Single ingestion entry point. URL sources go through WebPageFetcher; PDF sources are
 * resolved to a local file and run through a per-page tier dispatcher honoring pdf_mode:
 *   - auto:   tier 1 (embedded text); sparse page -> tier 2 (Vision OCR); tabular page -> tier 3 (layout)
 *   - text:   tier 1 for every page
 *   - vision: tier 2 for every page
 *   - tables: tier 3 for every page
 */
final class SourceIngestionService implements SourceIngestionServiceInterface
{
    public function __construct(
        private readonly WebPageFetcher $webPageFetcher,
        private readonly PdfFileResolver $pdfFileResolver,
        private readonly PdfTextExtractor $textExtractor,
        private readonly PdfVisionExtractor $visionExtractor,
        private readonly PdfLayoutExtractor $layoutExtractor,
    ) {}

    public function ingest(array $jobRow): SourceDocument
    {
        $type = SourceType::tryFrom((string) ($jobRow['source_type'] ?? ''));
        if ($type === null) {
            throw new IngestionException('Unknown source_type: ' . (string) ($jobRow['source_type'] ?? ''), 1749379450);
        }

        return match ($type) {
            SourceType::Url => $this->ingestUrl((string) ($jobRow['source_value'] ?? '')),
            SourceType::PdfUrl, SourceType::PdfFal => $this->ingestPdf($jobRow),
        };
    }

    private function ingestUrl(string $url): SourceDocument
    {
        if (trim($url) === '') {
            throw new IngestionException('url job has an empty source_value', 1749379451);
        }

        return $this->webPageFetcher->fetch($url);
    }

    /** @param array<string,mixed> $jobRow */
    private function ingestPdf(array $jobRow): SourceDocument
    {
        $mode = PdfMode::fromJobValue((string) ($jobRow['pdf_mode'] ?? 'auto'));
        $beUser = (int) ($jobRow['be_user'] ?? 0);
        $absPath = $this->pdfFileResolver->resolve($jobRow);

        $pages = $this->textExtractor->extract($absPath);

        $texts = [];
        $tiers = [];
        foreach ($pages as $page) {
            [$text, $tier] = $this->extractPage($absPath, $page, $mode, $beUser);
            if (trim($text) !== '') {
                $texts[] = $text;
                $tiers[$tier] = true;
            }
        }

        $body = trim(implode("\n\n", $texts));
        if ($body === '') {
            throw new IngestionException('No text could be extracted from the PDF: ' . $absPath, 1749379452);
        }

        return new SourceDocument(
            title: '',
            text: $body,
            sourceLabel: basename($absPath),
            pageCount: count($pages),
            languageHint: '',
            meta: ['tiersUsed' => $this->orderTiers($tiers)],
        );
    }

    /**
     * @param array{page:int,text:string,isSparse:bool} $page
     * @return array{0:string,1:string} [pageText, tierLabel]
     */
    private function extractPage(string $absPath, array $page, PdfMode $mode, int $beUser): array
    {
        return match ($mode) {
            PdfMode::Text => [$page['text'], 'text'],
            PdfMode::Vision => [$this->visionExtractor->ocrPage($absPath, $page['page'], $beUser), 'vision'],
            PdfMode::Tables => [$this->layoutExtractor->extractPage($absPath, $page['page']), 'tables'],
            PdfMode::Auto => $this->autoPage($absPath, $page, $beUser),
        };
    }

    /**
     * @param array{page:int,text:string,isSparse:bool} $page
     * @return array{0:string,1:string}
     */
    private function autoPage(string $absPath, array $page, int $beUser): array
    {
        if ($page['isSparse']) {
            return [$this->visionExtractor->ocrPage($absPath, $page['page'], $beUser), 'vision'];
        }
        if ($this->looksTabular($page['text'])) {
            return [$this->layoutExtractor->extractPage($absPath, $page['page']), 'tables'];
        }

        return [$page['text'], 'text'];
    }

    /** Cheap table heuristic: 3+ lines with a run of 2+ spaces between non-space chars (column gutters). */
    private function looksTabular(string $text): bool
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $aligned = 0;
        foreach ($lines as $line) {
            if (preg_match('/\S {2,}\S/', $line) === 1) {
                $aligned++;
            }
        }

        return $aligned >= 3;
    }

    /**
     * @param array<string,bool> $tiers
     * @return list<string>
     */
    private function orderTiers(array $tiers): array
    {
        $ordered = [];
        foreach (['text', 'vision', 'tables'] as $tier) {
            if (isset($tiers[$tier])) {
                $ordered[] = $tier;
            }
        }

        return $ordered;
    }
}
```

- [ ] **Step 5: Run the unit test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter SourceIngestionServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Register the interface alias in `Configuration/Services.yaml`**

Add this alias so consumers (Plan 3 orchestrator) can autowire the interface. The strategy classes (`WebPageFetcher`, `PdfFileResolver`, `PdfTextExtractor`, `PdfVisionExtractor`, `PdfLayoutExtractor`, `SourceIngestionService`) and `SymfonyProcessPopplerRunner` are autowired by the existing `Netresearch\NrRepurpose\: resource: '../Classes/*'` block; only the two interface aliases need explicit entries. Add to the `services:` section (after the existing CapabilityPermission alias from Plan 1):

```yaml
  Netresearch\NrRepurpose\Ingestion\Poppler\PopplerRunnerInterface:
    alias: Netresearch\NrRepurpose\Ingestion\Poppler\SymfonyProcessPopplerRunner

  Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface:
    alias: Netresearch\NrRepurpose\Ingestion\SourceIngestionService
```

`WebPageFetcher`/`PdfFileResolver` depend on PSR-18 `ClientInterface` + `RequestFactoryInterface`; TYPO3 core registers both as public services, so autowiring resolves them with no extra entry.

- [ ] **Step 7: Write the functional test `Tests/Functional/Ingestion/SourceIngestionServiceTest.php`** (real text PDF + real HTML, Vision stubbed)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Ingestion;

use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\PdfLayoutExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfTextExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfVisionExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\SymfonyProcessPopplerRunner;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionService;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SourceIngestionServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-repurpose'];

    private function fixturePdf(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Pdf/sample-text.pdf';
    }

    private function htmlClient(): \Psr\Http\Client\ClientInterface
    {
        $html = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Web/article.html');

        return new class($html) implements \Psr\Http\Client\ClientInterface {
            public function __construct(private readonly string $html) {}
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write($this->html);
                $response->getBody()->rewind();
                return $response;
            }
        };
    }

    /** PDF that must never hit a real provider: fail loudly if auto escalates to Vision. */
    private function explodingVision(): PdfVisionExtractor
    {
        return new class(new SymfonyProcessPopplerRunner()) extends PdfVisionExtractor {
            public function ocrPage(string $absPdfPath, int $page, int $beUser, int $dpi = 200): string
            {
                throw new \LogicException('Vision must not be called for a text PDF in auto mode');
            }
        };
    }

    private function service(\Psr\Http\Client\ClientInterface $client, PdfVisionExtractor $vision): SourceIngestionService
    {
        $requestFactory = $this->get(RequestFactory::class);
        $runner = new SymfonyProcessPopplerRunner();

        return new SourceIngestionService(
            new WebPageFetcher($client, $requestFactory),
            new PdfFileResolver($this->get(ResourceFactory::class), $client, $requestFactory),
            new PdfTextExtractor(),
            $vision,
            new PdfLayoutExtractor($runner),
        );
    }

    public function testIngestsStaticHtmlIntoSourceDocument(): void
    {
        $doc = $this->service($this->htmlClient(), $this->explodingVision())
            ->ingest(['uid' => 1, 'source_type' => 'url', 'source_value' => 'https://example.com/q1', 'be_user' => 0]);

        self::assertInstanceOf(SourceDocument::class, $doc);
        self::assertSame('Quarterly Results 2026', $doc->title);
        self::assertStringContainsString('Revenue grew by 12 percent', $doc->text);
        self::assertSame(0, $doc->pageCount);
        self::assertSame('static', $doc->meta['fetchedVia']);
    }

    public function testIngestsRealTextPdfFalSourceViaAutoTier1(): void
    {
        // Attach the fixture PDF to FAL and reference it via source_pdf.
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $folder = $storage->hasFolder('repurpose') ? $storage->getFolder('repurpose') : $storage->createFolder('repurpose');
        $file = $storage->createFile('ingest-test.pdf', $folder);
        $file->setContents((string) file_get_contents($this->fixturePdf()));

        $doc = $this->service($this->htmlClient(), $this->explodingVision())
            ->ingest([
                'uid' => 2, 'source_type' => 'pdf_fal', 'source_pdf' => $file->getUid(),
                'pdf_mode' => 'auto', 'be_user' => 0,
            ]);

        self::assertStringContainsString('Net revenue rose to 48 million euro', $doc->text);
        self::assertSame(1, $doc->pageCount);
        self::assertContains('text', $doc->meta['tiersUsed']);  // dense text page -> tier 1, no Vision
        self::assertNotContains('vision', $doc->meta['tiersUsed']);
    }
}
```

> Uses `TYPO3\CMS\Core\Http\Response`/`RequestFactory` (always available in the functional context) for the fake HTTP layer, and the real `SymfonyProcessPopplerRunner` + real text-PDF fixture for the PDF branch. The `explodingVision` fake guarantees the auto dispatcher does NOT call a provider for a dense text PDF — the only real external call here is Poppler (Tier 1 uses smalot, no binary). Vision/FAL-remote real calls are reserved for the Plan 3+ orchestrator smoke run.

- [ ] **Step 8: Run the functional test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter 'Netresearch\\NrRepurpose\\Tests\\Functional\\Ingestion\\SourceIngestionServiceTest'"`
Expected: PASS (2 tests).

- [ ] **Step 9: Commit**

```bash
git add Classes/Ingestion/SourceIngestionServiceInterface.php Classes/Ingestion/SourceIngestionService.php Configuration/Services.yaml Tests/Unit/Ingestion/SourceIngestionServiceTest.php Tests/Functional/Ingestion/SourceIngestionServiceTest.php
git commit -s -m "Add SourceIngestionService with per-page auto tier dispatcher"
```

---

## Task 13: Full suite gate

**Files:** none (verification gate).

- [ ] **Step 1: Run the full unit suite (host)**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml`
Expected: all unit tests PASS (Plan 1 enum tests + Plan 2: `PdfModeTest`, `SourceDocumentTest`, `IngestionExceptionTest`, `WebPageFetcherTest`, `PdfTextExtractorTest`, `PdfVisionExtractorTest`, `PdfLayoutExtractorTest`, `SourceIngestionServiceTest`).

- [ ] **Step 2: Run the full functional suite (DDEV DB)**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml"`
Expected: all functional tests PASS (Plan 1: `JobRepositoryTest`, `JobProcessingRepositoryTest`, `JobFileStorageTest`, `GenerationOrchestratorTest`, `JobControllerTest`; Plan 2: `JobPdfFieldsTest`, `SymfonyProcessPopplerRunnerTest`, `PdfFileResolverTest`, `SourceIngestionServiceTest`).

- [ ] **Step 3: Confirm the orchestrator was left untouched**

Run: `cd /home/sme/p/nr-repurpose/main && git diff --name-only HEAD~12 -- Classes/Service Classes/Queue Classes/Generator`
Expected: NO output — Plan 2 did not modify the orchestrator, the Messenger handler, or the stub generator (those are Plan 3's job). If any of those paths appear, revert the unrelated change.

- [ ] **Step 4: Commit (only if Step 3 surfaced an accidental change to revert; otherwise nothing to commit)**

```bash
git status --short
```
Expected: clean working tree (all Plan 2 work already committed in Tasks 1–12).

---

## Self-Review

- **Spec coverage (Plan 2 slice):**
  - §7 Ingestion — `SourceIngestionService` (Task 12) selects by `source_type` + `pdf_mode` and runs the per-page auto dispatcher (text → vision-if-sparse → layout-if-tabular) exactly as the grounding PDF area's dispatcher pseudocode; `WebPageFetcher` (Task 6, PSR-18 + deterministic main-content extraction, static-HTML default, documented Chromium seam for Plan 4); `PdfTextExtractor` (Task 7, smalot per-page + sparse detection + `setIgnoreEncryption(true)` + `\Throwable` catch for encrypted); `PdfVisionExtractor` (Task 9, `pdftoppm` → data URI → `VisionService::analyzeImage`); `PdfLayoutExtractor` (Task 10, `pdftotext -layout`); `IngestionException` (Task 5).
  - §5 PDF source fields — `source_pdf` TCA `type=file` (auto DB column since v13, no SQL entry) + `pdf_mode` enum column with `ext_tables.sql` entry, surfaced on the Job model and TCA (Task 4).
  - §11 BE New form — source-type selector + `pdf_mode` selector added (Task 4 Step 6), with the documented v14 split for FAL upload.
  - Out of scope and deliberately untouched: orchestrator/handler/stub generator (Plan 3 wires ingestion + adds `ContentBrief`/`GenerationContext`), render-infra (Plan 4), generators (Plan 5), result view/themes (Plan 6) — verified by Task 13 Step 3.
- **Type consistency vs. contracts doc:**
  - `SourceDocument` constructor (`title, text, sourceLabel, pageCount, languageHint, meta=[]`) is byte-for-byte the contracts signature; `meta` carries `tiersUsed`/`fetchedVia` exactly as the contract example.
  - `SourceIngestionServiceInterface::ingest(array $jobRow): SourceDocument` with `@throws IngestionException` matches the contracts interface verbatim; strategy class names (`WebPageFetcher`, `PdfTextExtractor`, `PdfVisionExtractor`, `PdfLayoutExtractor`) match the contracts list, all `final`, each its own class.
  - The job row read keys (`source_type`, `source_value`, `source_pdf`, `pdf_mode`, `be_user`, `uid`) match the Plan 1 `ext_tables.sql` columns plus the two columns added here; `be_user` feeds the nr-llm budget guard via `VisionOptions::withBeUserUid()` consistent with the contracts' budget-guard note.
  - Plan 1's `JobProcessingRepository::findRow(): ?array<string,mixed>` is the row shape `ingest()` consumes — no new persistence methods introduced (the contracts' `updateArtifact`/`insertArtifact` belong to Plan 5).
- **API grounding (no guessed APIs):** smalot `Parser([], $config)` + `Config::setIgnoreEncryption(true)` + `getPages()`/`Page::getText()` (grounding PDF [2]–[4]); Poppler flags `-png -r -f -l -singlefile` and `-layout -enc UTF-8 -nopgbrk -q` (grounding PDF [5],[6]); `VisionServiceInterface::analyzeImage(string|array,string,?VisionOptions)` + data-URI acceptance + `VisionOptions::withMaxTokens`/`withBeUserUid` (grounding nr-llm [7],[8] + PDF [9]); TCA `type=file` auto DB column since v13 (grounding BE [11]). Each non-obvious use is cited inline in the plan prose.
- **Placeholder scan:** none. Every code step contains complete, runnable code (no TODO/TBD/"similar to above"); every command shows the exact `.Build/bin/phpunit -c Build/phpunit/UnitTests.xml` (host) or `ddev exec "...FunctionalTests.xml..."` invocation with explicit expected FAIL/PASS output. TDD ordering (write test → run FAIL → implement → run PASS → `git commit -s`) is followed in every implementation task. The two notes (Task 9 Vision-interface signature drift; Task 11 FAL uid-vs-reference shape) are explicit contingencies with concrete fallbacks and a stated invariant under test, not vague deferrals.
- **Commit hygiene:** all commits use `git commit -s` (DCO), English messages, no AI/bot attribution, no emojis.
