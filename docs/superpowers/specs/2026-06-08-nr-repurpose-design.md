# nr_repurpose — Design-Spec

**Datum:** 2026-06-08
**Extension-Key:** `nr_repurpose` · **Composer:** `netresearch/nr-repurpose`
**Status:** Design abgestimmt, bereit für Implementierungsplanung

---

## 1. Kontext & Ziel

`nr_repurpose` ist eine TYPO3-Extension, die aus **einer Quelle** (Webseite-URL oder PDF — als FAL-Datei oder URL) automatisch **drei Medienformate** erzeugt:

1. **Podcast** — Zwei-Stimmen-Dialog als Audiodatei (mp3), inkl. **Transkript** (Text) und **Untertiteln** (WebVTT)
2. **Schaubild** — Diagramm/Infografik als Pixelgrafik (PNG), in **drei Varianten** zum Vergleich
3. **Instagram-Story** — ein 9:16-Key-Visual (1080×1920 PNG)

Die Quellen sind typischerweise **komplexe Dokumente und komplexe Sachverhalte**. Treue von Text, Zahlen und Beschriftungen hat darum Vorrang vor Optik, wo es um Fakten geht.

Die Extension baut auf **`netresearch/nr-llm`** auf — der geteilten KI-Grundlage für TYPO3 (Provider-Abstraktion, verschlüsselte Keys, Budget/Usage-Tracking, Caching). `nr_repurpose` bringt keine eigene Provider- oder Key-Logik mit; sie konsumiert ausschließlich nr-llm-Services.

**Liefergegenstand:** eine lauffähige TYPO3-v14.3-Instanz (DDEV), in der ein Redakteur im Backend eine URL bzw. ein PDF angibt und die drei Artefakte erzeugt.

---

## 2. Scope

### Im MVP enthalten

- Quelle: Webseite-URL · PDF über URL · PDF aus FAL
- PDF-Verarbeitung in drei Stufen: Text-Extraktion · Scan/OCR via Vision · Tabellen-/Layout-bewusste Extraktion
- Podcast als Zwei-Stimmen-Dialog
- Schaubild in drei Varianten (siehe §9)
- Instagram-Story als einzelner 9:16-Slide
- Ausgabesprache automatisch nach Quellsprache
- Theme-Schalter: Netresearch-CI **und** neutral/white-label, pro Lauf wählbar
- Asynchrone Verarbeitung über Symfony Messenger mit Status/Progress im Backend
- Backend-Modul mit Eingabeformular, Job-Liste und Ergebnis-Ansicht

### Bewusst außerhalb des MVP (YAGNI)

- Mehrteilige Story / Karussell-Post (Story bleibt 1× 9:16)
- Hintergrundmusik, Jingles, mehr als zwei Sprecherstimmen
- Video-Ausgabe
- Frontend-Plugin (Ausgabe nur im Backend)
- Theme-Varianten über NR-CI und neutral hinaus
- In-Backend-Editor zum Nachbearbeiten des erzeugten HTML (HTML wird gespeichert, aber nicht im BE editiert)
- TYPO3 v13 (MVP zielt ausschließlich auf v14.3)

---

## 3. Entscheidungen (Decision Log)

| Thema | Entscheidung | Begründung |
|---|---|---|
| Schaubild-Strategie | LLM → gebrandetes HTML → deterministischer Render | Beschriftungen exakt, CI-konform, reproduzierbar |
| Schaubild-Varianten | **alle drei** parallel erzeugen | empirischer Vergleich gewünscht |
| Render-Engine | Headless Chromium (Playwright) | beste Layout-Treue; nr-llm bringt Playwright bereits mit; dient zugleich als Fetcher für JS-lastige Seiten |
| Bild-KI-Einsatz | Schaubild-Variante 2/3 + optionaler Story-Hintergrund | KI für Optik, nie für Fakten/Text |
| Podcast-Format | Zwei-Stimmen-Dialog (nova + onyx) | lebendig bei komplexen Themen |
| Story-Umfang | einzelner 9:16-Slide | schlanker MVP, später erweiterbar |
| Ausführung | asynchron über Symfony Messenger | keine Timeouts, Fortschritt sichtbar, moderne v14-Variante |
| Zielplattform | TYPO3 v14.3, PHP ^8.3 | v14-only erlaubt Messenger/Fluid-5/Backend-Module-API ohne Kompat-Ballast |
| PDF-Umfang | Text + Vision-OCR + Tabellen/Layout, **Stufe pro Lauf wählbar (Default `auto`)** | komplexe Dokumente erfordern alle drei Stufen; `auto` staffelt automatisch |
| Branding | NR-CI **und** neutral, umschaltbar | Demo-tauglich und kundenprojekt-tauglich |
| Sprache | Quellsprache automatisch | deckt DE/EN ohne Zusatz-UI ab |
| Artefakt-Auswahl | Redakteur wählt pro Lauf (Podcast/Schaubild/Story), Default alle | gezielte und günstigere Läufe möglich |
| Podcast-Länge | nach Dokumentumfang (LLM-bestimmt) | keine künstliche Zielvorgabe; folgt dem Inhalt |
| Podcast-Transkript/Untertitel | Transkript (Text) + WebVTT-Untertitel aus Segment-Dauern | lesbar/barrierearm, kein zusätzlicher Provider-Aufruf |

---

## 4. Architektur

### Datenfluss

```
  Backend-Modul "Content Studio"
  ┌───────────────────────────────────────────────┐
  │ URL eingeben  |  PDF (FAL/URL) wählen          │
  │ Optionen: Theme (NR|neutral), Artefakt-Auswahl │
  └───────────────┬────────────────────────────────┘
                  │ legt tx_repurpose_job an (status=queued)
                  │ dispatcht GenerateArtifactsMessage(jobUid)
                  ▼
  ┌───────────────────────────────────────────────┐
  │  Messenger-Worker (messenger:consume)          │
  │  ProcessGenerationJobHandler → Orchestrator    │
  │                                                │
  │  1 INGEST   URL→Readability | PDF→Text/OCR/     │
  │             Tabellen ───────────► SourceDocument│
  │  2 ANALYZE  nr-llm CompletionService ──►        │
  │             ContentBrief (Titel, Kernaussagen,  │
  │             Sprache, Struktur; strukturiertes   │
  │             JSON, ggf. Map-Reduce bei großen Doks)│
  │  3 GENERATE pro Artefakt isoliert:              │
  │     ├ Podcast  Skript→TTS×2→ffmpeg→mp3          │
  │     ├ Schaubild HTML→{3 Varianten}→PNG          │
  │     └ Story     9:16-HTML→PNG (+opt. KI-BG)     │
  │  4 STORE    Dateien→FAL, Status→done/partial    │
  └───────────────┬────────────────────────────────┘
                  ▼
  Ergebnis-Ansicht im BE: Audio-Player, Bild-Previews,
  Download, "HTML ansehen", Status pro Artefakt
```

### Schichten

Jede Unit hat **eine Aufgabe**, kommuniziert über ein Interface und ist einzeln testbar.

- **Ingestion** — wandelt eine Quelle in ein einheitliches `SourceDocument` (Reintext + Metadaten).
- **Understanding** — verdichtet das `SourceDocument` zu einem `ContentBrief` (Titel, Zusammenfassung, Kernaussagen, Struktur, erkannte Sprache).
- **Generators** — drei Generatoren hinter `ArtifactGeneratorInterface`, die aus dem `ContentBrief` je ein Artefakt (bzw. mehrere Varianten) erzeugen.
- **Render-Infra** — geteilte Werkzeuge: HTML→PNG, Bild-Komposition, Audio-Zusammenführung.
- **Orchestrierung** — Messenger-Handler, `GenerationOrchestrator`, `JobRepository`.
- **Integration nr-llm** — dünne Adapter auf die nr-llm-Services.
- **Backend** — Modul, Controller, Fluid-Templates.

---

## 5. Datenmodell

### `tx_repurpose_job`

| Feld | Typ | Bedeutung |
|---|---|---|
| `uid`, `pid`, `tstamp`, `crdate` | — | Standard |
| `cruser_id` / `be_user` | int | erzeugender Backend-User (für Budget/Permissions) |
| `source_type` | enum | `url` \| `pdf_url` \| `pdf_fal` |
| `source_value` | text | URL (bei `url`/`pdf_url`) |
| `source_file` | sys_file-Ref | FAL-PDF (bei `pdf_fal`) |
| `theme` | enum | `nr` \| `neutral` |
| `requested_artifacts` | set | `podcast`, `schaubild`, `story` — vom Redakteur wählbar (Default: alle) |
| `pdf_mode` | enum | `auto` (Default) \| `text` \| `vision` \| `tables` — nur bei PDF-Quellen |
| `language_detected` | string | von der Analyse gefüllt |
| `status` | enum | `queued`, `ingesting`, `analyzing`, `generating`, `done`, `partially_done`, `failed` |
| `progress` | int | 0–100 (für BE-Anzeige) |
| `current_step` | string | menschenlesbarer Schritt |
| `error_message` | text | bei `failed` |
| `options` | json | Feinkonfiguration (Stimmen, Modelle, Viewport …) |

### `tx_repurpose_artifact`

| Feld | Typ | Bedeutung |
|---|---|---|
| `uid`, `pid`, `tstamp`, `crdate` | — | Standard |
| `job` | int (parent) | zugehöriger Job |
| `type` | enum | `podcast` \| `schaubild` \| `story` |
| `variant` | enum | Schaubild: `html` \| `html_bg` \| `ki_image`; sonst `default` |
| `file` | sys_file-Ref | erzeugte Datei (mp3/PNG) in FAL |
| `source_html` | text | erzeugtes HTML (Schaubild/Story) — zur Nachvollziehbarkeit |
| `script_text` | text | Dialog-Transkript (Podcast), Sprecher-getaggt |
| `subtitle_file` | sys_file-Ref | WebVTT-Untertitel (Podcast) |
| `status` | enum | `pending`, `done`, `failed` |
| `error_message` | text | bei `failed` |
| `metadata` | json | Provider, Modell, Stimmen, Maße, Kosten/Tokens |

Audio- und Bilddateien liegen in **FAL** (eigener Storage/Ordner `repurpose/`), die Records referenzieren sie über `sys_file`. Der `ContentBrief` und das `SourceDocument` sind Value Objects; sie werden zur Nachvollziehbarkeit optional als JSON am Job hinterlegt, sind aber nicht persistenz-führend.

---

## 6. Pipeline & Job-Lebenszyklus

1. **Anlage** — Das BE-Formular validiert die Eingabe, legt `tx_repurpose_job` (`status=queued`) an und dispatcht `GenerateArtifactsMessage(jobUid)` auf den **Doctrine-Transport** des TYPO3-integrierten Messenger-Bus.
2. **Verarbeitung** — `#[AsMessageHandler] ProcessGenerationJobHandler` lädt den Job und ruft den `GenerationOrchestrator`. Der **Job-Record bleibt Source-of-Truth** für Status und Progress (das BE liest daraus); Messenger liefert Transport, Retry und Failure-Transport.
3. **Schritte** — Ingestion → Analyse → Generierung → Speicherung. Nach jedem Schritt aktualisiert der Orchestrator `status`, `progress` und `current_step`.
4. **Abschluss** — Status `done`, wenn alle angeforderten Artefakte erfolgreich sind; `partially_done`, wenn mindestens eines fehlschlägt; `failed`, wenn die Pipeline vor der Generierung abbricht (z. B. Quelle nicht erreichbar, Budget überschritten).

Der Worker läuft über `vendor/bin/typo3 messenger:consume` (im DDEV als eigener Worker-Service; ein `failed`-Transport sammelt nicht zustellbare Nachrichten).

---

## 7. Komponenten im Detail

### Ingestion

- `SourceIngestionService` — wählt anhand `source_type` und Inhaltstyp die Strategie und liefert ein `SourceDocument` (Reintext, Titel, Quell-URL, Seitenzahl, Sprache-Hinweis).
- `WebPageFetcher` — lädt die Seite. Statisches HTML direkt; bei JS-lastigen Seiten rendert **Chromium** den DOM. Anschließend extrahiert ein Readability-Schritt den Hauptinhalt (Navigation/Boilerplate raus).
- `PdfTextExtractor` — extrahiert eingebetteten Text (poppler `pdftotext` bzw. `smalot/pdfparser`).
- `PdfVisionExtractor` — rendert Seiten ohne Textebene als Bild und gibt sie an nr-llm `VisionService` (OCR/Verständnis).
- `PdfTableExtractor` — erkennt und strukturiert Tabellen/Spalten-Layouts für treue Übernahme in den `ContentBrief`.

Die PDF-Strategie richtet sich nach `pdf_mode`. Bei `auto` (Default) sind die Stufen gestaffelt: zuerst Text-Extraktion; liefert sie zu wenig (z. B. Scan ohne Textebene), greift Vision; Tabellen-Extraktion ergänzt strukturierte Bereiche. Alternativ erzwingt der Redakteur eine Stufe (`text` / `vision` / `tables`) explizit pro Lauf.

### Understanding

- `DocumentAnalyzer` — erzeugt über nr-llm `CompletionService` (JSON-/Tool-Mode) genau **einen** `ContentBrief`. Bei großen Dokumenten greift ein **Map-Reduce**: abschnittsweise Zusammenfassung, dann Gesamtsynthese, um Token-Limits einzuhalten.
- `ContentBrief` (Value Object) — `title`, `summary`, `keyPoints[]`, `sections[]`, `audience`, `language`. Alle drei Generatoren teilen sich dieses eine Ergebnis (eine Analyse pro Lauf, nicht drei).

### Generators (`ArtifactGeneratorInterface`)

- `SchaubildGenerator` (§8)
- `PodcastGenerator` (§9)
- `StoryGenerator` (§10)

### Render-Infra

- `HtmlToImageRenderer` — rendert HTML bei definiertem Viewport über Playwright/Chromium zu PNG (z. B. Schaubild dynamische Höhe, Story 1080×1920). Anbindung über einen schlanken Node-Renderer (via Symfony `Process`) oder `spatie/browsershot` — Entscheidung in der Planung, hinter dem Interface gekapselt.
- `ImageCompositor` (Imagick) — legt eine transparente HTML-Text-Ebene über ein Hintergrundbild.
- `AudioStitcher` (ffmpeg) — fügt die TTS-Segmente der zwei Stimmen in der richtigen Reihenfolge zu einer mp3 zusammen.

### Integration nr-llm (verifizierte Services)

| Bedarf | nr-llm-Service |
|---|---|
| Analyse, Skript, HTML-Erzeugung | `CompletionService` / `LlmServiceManager` |
| OCR/Bildverständnis | `VisionService` |
| Sprachsynthese (Podcast) | `Specialized\Speech\TextToSpeechService` (`synthesize`, Stimmen alloy/echo/fable/onyx/nova/shimmer, Formate mp3/opus/wav) |
| Bildgenerierung / img2img | `Specialized\Image\FalImageService` (FLUX/SDXL, 9:16-Portrait, `imageToImage`) bzw. `DallEImageService` |
| Kosten-Guard | `BudgetService` (vor jedem Lauf) |
| Berechtigungen | `CapabilityPermissionService` |

---

## 8. Schaubild — drei Varianten

Gemeinsame Basis: Der `SchaubildGenerator` lässt nr-llm aus dem `ContentBrief` ein **gebrandetes HTML/CSS-Dokument** erzeugen (Theme nr/neutral), das ein Diagramm/eine Infografik darstellt.

1. **`html`** — `HtmlToImageRenderer` macht daraus per Chromium-Screenshot direkt das PNG. Text/Beschriftungen 100 % korrekt. *(Referenz-Variante.)*
2. **`html_bg`** — nr-llm (`FalImageService`/`DallEImageService`) erzeugt ein passendes **Hintergrundbild**; dasselbe HTML wird mit transparentem Hintergrund gerendert; `ImageCompositor` legt die exakte Text-Ebene über das KI-Bild.
3. **`ki_image`** — der Chromium-Screenshot der `html`-Variante dient als **Struktur-Vorlage** für `FalImageService::imageToImage`. So entsteht ein vollständig KI-gerendertes Bild, das der Struktur folgt — bewusst zum Vergleich, faktisch erwartbar die schwächste Variante.

Alle drei werden als eigene `tx_repurpose_artifact`-Records (gleicher `type=schaubild`, unterschiedliche `variant`) gespeichert.

---

## 9. Podcast

- `PodcastGenerator` erzeugt über nr-llm ein **Dialog-Skript** zweier Hosts (Host A / Host B) aus dem `ContentBrief` — strukturiert als Folge von Sprecher-Turns in der erkannten Quellsprache. Die **Länge richtet sich nach dem Dokumentumfang** (LLM-bestimmt) — keine feste Zielvorgabe.
- Pro Turn ruft der Generator `TextToSpeechService::synthesize` mit der jeweiligen Stimme (Default Host A = `nova`, Host B = `onyx`), Format mp3.
- `AudioStitcher` (ffmpeg) fügt die Segmente in Reihenfolge zu einer mp3 zusammen (einheitliches Format/Samplerate, saubere Übergänge).
- Aus den Sprecher-Turns entsteht ein **Transkript** (Sprecher-getaggt, in `script_text`) und daraus eine **WebVTT-Untertiteldatei**: die Cue-Zeiten ergeben sich aus den per `ffprobe` gemessenen Segment-Dauern — also ohne zusätzlichen Provider-Aufruf und passend zur erzeugten Audiospur.
- Ergebnis: ein `artifact` (`type=podcast`) mit mp3 (`file`) und WebVTT (`subtitle_file`) in FAL; das Transkript liegt in `script_text` und ist als Text herunterladbar.

---

## 10. Instagram-Story

- `StoryGenerator` lässt nr-llm aus dem `ContentBrief` Kernaussage + Titel verdichten und in ein **9:16-HTML-Template** (1080×1920, Theme nr/neutral) füllen.
- `HtmlToImageRenderer` rendert zu PNG. Optional (wie Schaubild-Variante 2) ein KI-Hintergrund + exakte Text-Ebene per `ImageCompositor`.
- Ergebnis: ein `artifact` (`type=story`), PNG in FAL.

---

## 11. Backend-Modul „Content Studio"

- **Eingabe** — Formular: Quelltyp (URL / PDF-URL / FAL-PDF), Wert bzw. Datei-Auswahl, Theme, **Artefakt-Auswahl** (Podcast/Schaubild/Story), bei PDF zusätzlich **Extraktionsstufe** (`auto` / `text` / `vision` / `tables`). Validierung vor Anlage.
- **Job-Liste** — laufende und abgeschlossene Jobs mit Status, Progress und Schritt.
- **Ergebnis-Ansicht** — Audio-Player mit **Untertiteln** und ausklappbarem **Transkript** (Podcast), Bild-Previews (Schaubild-Varianten nebeneinander, Story), Download-Links (mp3 / WebVTT / Transkript / PNG), „HTML ansehen", Status/Fehler pro Artefakt.
- **Berechtigungen/Budget** — über nr-llm `CapabilityPermissionService` und `BudgetService`; ein Lauf startet nur, wenn das Budget reicht.

---

## 12. Fehlerbehandlung & Resilienz

- **Artefakt-Isolation** — jeder Generator läuft eigenständig; ein fehlgeschlagenes Schaubild beendet nicht den Podcast. Der Job wird `partially_done`, das betroffene `artifact` trägt `status=failed` + `error_message`.
- **Typisierte Exceptions** — nr-llm wirft Exceptions mit Provider-Kontext; der Orchestrator fängt sie ab, protokolliert sie und vermerkt sie am Artefakt.
- **Budget-Guard** — vor dem Lauf; reicht das Budget nicht, schlägt der Job kontrolliert fehl (`failed`), ohne Provider-Aufrufe.
- **Retry** — transiente Provider-Fehler lösen einen Messenger-Retry mit Backoff aus; endgültig nicht zustellbare Nachrichten landen im `failed`-Transport.
- **Eingabefehler** — nicht erreichbare URL, leeres PDF, unlesbarer Scan → früher, klarer Abbruch mit verständlicher Meldung im BE.

---

## 13. Konfiguration

- `ext_conf_template.txt` — Defaults: Stimmen Host A/B, TTS-Modell, Bild-Provider/-Modell, Viewport-Maße, Default-Theme, FAL-Storage/Ordner, Map-Reduce-Schwelle.
- Provider/Keys ausschließlich über **nr-llm** (verschlüsselt) — `nr_repurpose` speichert keine Keys.
- Themes als Fluid/HTML-Templates (`Resources/Private/Templates/Schaubild/`, `.../Story/`), je `nr` und `neutral`, über TypoScript/`ext_conf` überschreibbar.

---

## 14. Tests (typo3-testing-Skill)

- **Unit** — `DocumentAnalyzer`-Prompting/Map-Reduce-Logik, Varianten-Auswahl, `ImageCompositor`-Komposition, `AudioStitcher`-Kommandobau, Skript-Strukturierung — mit gefakten nr-llm-Services und gefakten Renderern.
- **Functional** — Job-Lebenszyklus, `JobRepository`, `ProcessGenerationJobHandler`, BE-Controller, FAL-Ablage.
- **Isolation** — Render (Chromium), ffmpeg und Provider-Aufrufe liegen hinter Interfaces und werden in Tests gemockt.
- **Smoke (optional)** — ein DDEV-E2E-Lauf mit einem echten Beispiel-Dokument.

---

## 15. Liefergegenstand (laufende Instanz)

- Frische **DDEV TYPO3 v14.3**-Umgebung.
- `.ddev/web-build` ergänzt **Chromium (Playwright)**, **ffmpeg**, **poppler-utils**.
- Composer path-repo auf das lokale `nr-llm`-Checkout; beide Extensions installiert.
- Messenger-Worker als DDEV-Service (`messenger:consume`).
- Admin-User geseedet; nr-llm-Provider per `.env` vorkonfiguriert (nur API-Key eintragen).
- README: `ddev start` → Backend → **Content Studio** → URL/PDF angeben → Job läuft → Ergebnisse.

---

## 16. Risiken & offene Punkte (in Planung zu verifizieren)

- **Schaubild-Variante 3 (`ki_image`)** ist faktisch die schwächste — Zweck ist allein der Vergleich.
- **PDF-Tier 2/3 (OCR/Tabellen)** kosten Zeit/Geld pro Lauf; große Dokumente erfordern Map-Reduce vor der Analyse.
- **Messenger-Worker** muss dauerhaft laufen — in DDEV gelöst, auf Produktion ein Betriebsthema.
- **Kosten pro Lauf** (1× Analyse + 1× Skript + N× TTS + bis zu 3× Bildgenerierung) → Budget-Guard ist Pflicht.
- **Zu verifizieren:** exakte TYPO3-v14.3-Messenger-API (Bus-/Transport-Konfiguration, `#[AsMessageHandler]`), HTML→PNG-Anbindung (`spatie/browsershot` vs. eigener Node-Renderer), passende PDF-Bibliothek, FAL-Storage-API für programmatische Ablage.

---

## 17. Erfolgskriterien

1. Ein Redakteur gibt im Backend eine URL oder ein PDF an und erhält ohne weitere Schritte Podcast, Schaubild (3 Varianten) und Story.
2. Schaubild-Variante `html` zeigt **korrekte, lesbare** Beschriftungen aus dem Quelldokument.
3. Der Podcast ist ein hörbarer Zwei-Stimmen-Dialog in der Quellsprache, begleitet von Transkript und WebVTT-Untertiteln.
4. Die Story ist ein 1080×1920-PNG im gewählten Theme.
5. Lange Dokumente laufen ohne Timeout durch (asynchron); der Fortschritt ist im Backend sichtbar.
6. Ein fehlgeschlagenes Artefakt beendet nicht die übrigen.
7. `ddev start` liefert eine sofort bedienbare Instanz (nur API-Key nötig).
