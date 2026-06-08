# nr_repurpose — Cross-Plan Contracts

**Datum:** 2026-06-08
**Zweck:** Die Integrations-Schnittstellen, an denen sich Pläne 2–6 ausrichten MÜSSEN, damit die parallel geschriebenen Pläne zueinander passen. Jeder Plan referenziert exakt diese Namen/Signaturen. Verifizierte nr-llm/TYPO3-APIs: siehe `docs/superpowers/grounding/2026-06-08-cross-stack-api-grounding.md`.

Plan 1 (Walking Skeleton) ist die Basis. Pläne 2–6 bauen ausschließlich darauf auf und dürfen die hier definierten Verträge nicht abweichend benennen.

---

## Namespaces

| Namespace | Inhalt | Plan |
|---|---|---|
| `Netresearch\NrRepurpose\Ingestion\` | Fetcher/Extractoren, `SourceIngestionService` | 2 |
| `Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument` | Ingestion-Ergebnis | 2 |
| `Netresearch\NrRepurpose\Understanding\` | `DocumentAnalyzer` | 3 |
| `Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief` | Analyse-Ergebnis | 3 |
| `Netresearch\NrRepurpose\Pipeline\GenerationContext` | gebündelter Pipeline-Zustand | 3 |
| `Netresearch\NrRepurpose\Rendering\` | HTML→PNG, Compositor, Audio | 4 |
| `Netresearch\NrRepurpose\Generator\` | Podcast/Schaubild/Story-Generatoren | 5 |
| `Netresearch\NrRepurpose\Service\GenerationOrchestrator` | (aus Plan 1; in Plan 3 erweitert) | 1→3 |

---

## Value Objects

### `SourceDocument` (Plan 2 erzeugt)

```php
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

### `ContentBrief` (Plan 3 erzeugt)

```php
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
        public string $language,   // erkannte Quellsprache (ISO-639-1), steuert die Ausgabesprache
    ) {}
}
```

### `GenerationContext` (Plan 3 erzeugt, Generatoren konsumieren)

```php
final readonly class GenerationContext
{
    /** @param array<string,mixed> $jobRow rohe Job-DB-Zeile (JobProcessingRepository::findRow) */
    public function __construct(
        public array $jobRow,
        public SourceDocument $document,
        public ContentBrief $brief,
        public string $theme,   // 'nr' | 'neutral'
        public int $beUser,     // für BudgetService::check()
    ) {}

    public function jobUid(): int { return (int) $this->jobRow['uid']; }
}
```

---

## Interfaces

### Ingestion (Plan 2)

```php
interface SourceIngestionServiceInterface
{
    /** @param array<string,mixed> $jobRow @throws IngestionException bei nicht erreichbarer/unlesbarer Quelle */
    public function ingest(array $jobRow): SourceDocument;
}
```
Strategien (alle final, je eigene Klasse): `WebPageFetcher`, `PdfTextExtractor`, `PdfVisionExtractor`, `PdfLayoutExtractor`. Der `SourceIngestionService` wählt anhand `source_type` + `pdf_mode` (`auto`|`text`|`vision`|`tables`) die Strategie (Auto-Dispatcher pro Seite gemäß Grounding-Doc PDF-Area).

### Understanding (Plan 3)

```php
interface DocumentAnalyzerInterface
{
    /** @param array<string,mixed> $jobRow */
    public function analyze(SourceDocument $document, array $jobRow): ContentBrief;
}
```
Nutzt `CompletionServiceInterface::completeJson()` (nr-llm), Map-Reduce bei großem `$document->text` (Token-Limit). Budget-Guard via `ChatOptions->withBeUserUid($beUser)` (Pipeline-Service ist budget-überwacht).

### Generator (FINALE Signatur — Plan 3 migriert Plan-1-Stub darauf)

```php
interface ArtifactGeneratorInterface
{
    public function supports(GenerationContext $ctx): bool;   // z.B. $ctx->jobRow['want_podcast']
    public function generate(GenerationContext $ctx): bool;   // persistiert eigenes Artifact, kein throw bei Einzel-Fehler
}
```
> **Migration in Plan 3:** Plan 1 definiert `supports(array):bool` / `generate(array):bool`. Plan 3 ändert das Interface auf `GenerationContext` und passt `StubArtifactGenerator` + `GenerationOrchestrator` an. Pläne 5 implementieren ausschließlich die `GenerationContext`-Variante.

### Rendering (Plan 4)

```php
interface HtmlToImageRendererInterface
{
    /** @return string absoluter Pfad zur erzeugten PNG. $height=null => Auto-Höhe (fullPage). */
    public function render(string $html, int $width, ?int $height, float $deviceScaleFactor = 1.0, bool $transparent = false): string;
}

interface ImageCompositorInterface
{
    /** Legt $foregroundPng (transparent) über $backgroundPng (GD; Imagick ist NICHT installiert). */
    public function overlay(string $backgroundPng, string $foregroundPng, string $outPath): string;
}

interface AudioStitcherInterface
{
    /** @param list<string> $mp3Paths fügt in Reihenfolge zu einer mp3 (ffmpeg concat). */
    public function concat(array $mp3Paths, string $outPath): string;
    /** Dauer einer Audiodatei in Sekunden (ffprobe) — für WebVTT-Cue-Zeiten. */
    public function probeDurationSeconds(string $path): float;
}
```
Implementierung: `PlaywrightHtmlToImageRenderer` (Node-Script `Resources/Private/NodeRenderer/render.cjs` via Symfony `Process`, HTML über stdin, `--no-sandbox`, `CHROMIUM_PATH`), `GdImageCompositor`, `FfmpegAudioStitcher`. Binaries (chromium/ffmpeg/poppler) sind seit Plan 1 Task 2 im `.ddev/web-build` vorhanden.

---

## Orchestrator-Evolution (Plan 3)

`GenerationOrchestrator::process(int $jobUid)` wird erweitert zu:
1. `findRow` → wenn terminal: return (idempotent).
2. `markStatus(Ingesting,'ingesting',5)` → `SourceIngestionService::ingest($row)`.
3. `markStatus(Analyzing,'analyzing',20)` → `DocumentAnalyzer::analyze($doc,$row)`.
4. `GenerationContext` bauen (theme aus `$row['theme']`, beUser aus `$row['be_user']`).
5. `markStatus(Generating,'generating',…)` → für jeden Generator mit `supports($ctx)`: `generate($ctx)`, Progress fortschreiben.
6. Endstatus `Done`/`PartiallyDone`/`Failed` (Logik aus Plan 1 bleibt).
Fehler in Ingestion/Analyse → `markFailed` + Abbruch (kein Artefakt). Fehler in einem Generator → Artefakt `failed`, übrige laufen weiter. Der Messenger-Handler fängt weiterhin Top-Level-Exceptions (kein v14.3-Retry).

---

## Persistenz-Erweiterungen (Plan 5)

`JobProcessingRepository` (DBAL) erhält:
```php
public function updateArtifact(int $artifactUid, array $fields): void; // setzt subtitle_file_uid/source_html/script_text/metadata/status
```
Generatoren legen Artefakt zuerst `pending` an (`insertArtifact`), füllen Datei/HTML/Skript via `updateArtifact`. `JobFileStorage::store(string $content, string $filename): File` aus Plan 1 wird unverändert genutzt (mp3, png, vtt). Spalten `subtitle_file_uid`, `source_html`, `script_text`, `metadata` existieren bereits (Plan 1 `ext_tables.sql`).

## Budget/Capability (Plan 5)

Vor jedem **Specialized**-Aufruf (TTS/FAL/DALL-E — NICHT budget-überwacht):
```php
if (!$this->budget->check($ctx->beUser, $plannedCost)->allowed) { /* Artefakt failed, return false */ }
if (!$this->tts->isAvailable()) { /* Key fehlt → failed */ }
```
Capability-Perm-Optionen in `ext_localconf.php` registrieren (`ModelCapability::AUDIO`/`VISION` als nächstliegende Gates; es gibt keine IMAGE/SPEECH-Capability).

## Themes (Plan 5 liefert Templates, Plan 6 verfeinert)

Fluid/HTML-Templates pro Artefakt und Theme:
`Resources/Private/Templates/Generated/Schaubild/{Nr,Neutral}.html`, `.../Story/{Nr,Neutral}.html`. Der Generator rendert das Template (StandaloneView) zu HTML-String und gibt diesen an `HtmlToImageRendererInterface`. NR-Theme nutzt netresearch-branding (Logo, Farben #2F99A4/#FF4D00/#585961, Raleway/Open Sans). Für Story/Schaubild-Variante 2/3 emittiert das Template bei transparentem Render `html,body{background:transparent}`.
