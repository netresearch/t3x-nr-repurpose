# nr_repurpose — Content Repurpose for TYPO3

Turn a webpage (URL) or PDF into three AI-generated media artifacts — a two-host
**podcast** (with transcript + WebVTT subtitles), a **diagram** (Schaubild, in three
variants), and a 9:16 **Instagram-story carousel** — from the TYPO3 backend. Built on
[`netresearch/nr-llm`](https://github.com/netresearch/t3x-nr-llm).

## What it produces

From one source (URL or PDF) the pipeline derives a single faithful `ContentBrief`
(via nr-llm, source language auto-detected) and generates:

- **Podcast** — a two-host dialogue (voices `nova` + `onyx`), synthesized turn-by-turn
  via OpenAI TTS, stitched with ffmpeg into one MP3, plus a speaker-tagged transcript and
  a WebVTT subtitle file whose cue times come from the measured segment durations.
- **Schaubild** — three variants for comparison: pure HTML (Fluid → headless Chromium →
  PNG), HTML with an AI-generated background, and a full AI image. Branded NR or neutral
  theme.
- **Story** — a multi-slide 1080×1920 (9:16) carousel: a cover hook, one slide per key
  point (at most four) and an outro with the source attribution — up to six slides, one
  artifact per slide. A single optional AI background is shared by all slides and scaled
  to *cover* the design canvas so the layout is never distorted.

Each artifact type can be selected per run. Long-running generation runs asynchronously
via Symfony Messenger (doctrine transport).

## Requirements

- TYPO3 v14.3 LTS, PHP 8.5
- An **OpenAI API key** (chat/vision for analysis, TTS for the podcast, `gpt-image-1`
  for images). No other provider key is needed.
- `ffmpeg`, `poppler-utils` and `chromium` — baked into the DDEV web image.

## Local development (DDEV)

Prerequisites: Docker + DDEV. The siblings `../../t3x-nr-llm/main` and
`../../t3x-nr-vault/main` must exist (composer path-repository dependencies).

```bash
cp .ddev/.env.dist .ddev/.env     # then set OPENAI_API_KEY=sk-...
ddev start                        # builds the web image (ffmpeg, poppler-utils, chromium)
ddev install                      # composer install + TYPO3 v14.3 setup into .Build/Web
```

`ddev install` seeds the OpenAI key into nr-vault and wires nr-llm's default provider, so
no further configuration is required for a dev instance.

Backend: <https://nr-repurpose.ddev.site/typo3/> — user `admin`, password `Demo1234!`.
Open **Web › Repurpose**, choose *New job*, paste a URL, pick the artifacts and theme,
and submit. The list shows the job progress `queued → done`; the detail view plays the
podcast (with subtitles + transcript) and shows/downloads every image.

## How the OpenAI key is used

Since nr-llm v0.10.0 there is a single key-resolution path: the OpenAI key is stored in
**nr-vault** and every consumer reads it by identifier (`nr_repurpose_openai`). Both the
chat / vision providers and the Specialized services (TTS, images) authenticate through
nr-vault's audited secure HTTP client — no plaintext key is ever placed in extension
configuration (see nr-llm ADR-030).

`ddev install` seeds `OPENAI_API_KEY` into the vault under that identifier and sets
`apiKeyIdentifier = nr_repurpose_openai` + `defaultProvider = openai`. The dev wiring lives
in `config/system/additional.php` (written by `ddev install`).

## Configuration

Extension configuration (`ext_conf_template.txt`), surfaced through the typed
`RepurposeConfiguration`:

| Key | Default | Purpose |
|-----|---------|---------|
| `tts.model` | `tts-1-hd` | OpenAI TTS model |
| `image.provider` | `dalle` | image service (OpenAI images) |
| `image.model` | `gpt-image-1` | image model (DALL·E-3 was retired by OpenAI) |
| `defaultTheme` | `nr` | `nr` (branded) or `neutral` |
| `mapReduce.charThreshold` | `12000` | switch to chunked map-reduce analysis above this size |

## CLI

Run the full pipeline for a job synchronously (useful for ops / debugging without the
async worker):

```bash
.Build/bin/typo3 nr_repurpose:generate <jobUid>
```

## Tests

Always run via the Docker-isolated runner (TYPO3 core-testing images, default PHP 8.5) —
never inside ddev:

```bash
./Build/Scripts/runTests.sh -s unit                 # unit tests
./Build/Scripts/runTests.sh -s functional           # functional tests (sqlite)
./Build/Scripts/runTests.sh -s functional -d mariadb # functional against MariaDB
./Build/Scripts/runTests.sh -p 8.4 -s unit          # pin a different PHP version
```

The runner bind-mounts the sibling path dependencies (nr-llm, nr-vault) into the container
so their symlinked classes autoload.

## Architecture

See the rendered documentation under `Documentation/` (Introduction, Installation,
Configuration, Usage, Architecture, and the Architecture Decision Records). Pipeline:
ingest (web/PDF) → analyze (one `ContentBrief` via nr-llm) → generate (podcast /
schaubild×3 / story×N slides) → store in the TYPO3 File Abstraction Layer (FAL).
