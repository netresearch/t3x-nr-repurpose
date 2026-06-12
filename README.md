# nr_repurpose — Content Repurpose for TYPO3

Turn a webpage (URL) or PDF into three AI-generated media artifacts — a two-host
**podcast** (with transcript + WebVTT subtitles), a **diagram** (Schaubild, in three
variants), and a 9:16 **Instagram-story carousel** — from the TYPO3 backend.

Every AI call goes through [`netresearch/nr-llm`](https://github.com/netresearch/t3x-nr-llm):
nr_repurpose contains no provider code. Any LLM, image or TTS provider works — if
nr-llm supports it (see [Providers, models and prompts](#providers-models-and-prompts)).

## What it produces

From one source (URL or PDF) the pipeline derives a single faithful `ContentBrief`
(via nr-llm, source language auto-detected) and generates:

- **Podcast** — a two-host dialogue, synthesized turn-by-turn via nr-llm's
  text-to-speech service, stitched with ffmpeg into one MP3, plus a speaker-tagged
  transcript and a WebVTT subtitle file whose cue times come from the measured
  segment durations.
- **Schaubild** — three variants for comparison: pure HTML (Fluid → headless Chromium →
  PNG), HTML with an AI-generated background, and a full AI image. Branded NR or neutral
  theme.
- **Story** — a multi-slide 1080×1920 (9:16) carousel: a cover hook, one slide per key
  point (at most four) and an outro with the source attribution — up to six slides, one
  artifact per slide. A single optional AI background is shared by all slides and scaled
  to *cover* the design canvas so the layout is never distorted.

Each artifact type can be selected per run. Long-running generation runs asynchronously
via Symfony Messenger (doctrine transport).

## Editorial steering and transparency

- **Prompt snippets** — the job form offers *audience*, *tone of voice*, *persona*,
  *layout* and *style* selectors, populated from nr-llm's prompt-snippet library
  (each option shows its description). A layout snippet's `imageSize` metadata
  drives the AI-image dimensions per channel (skyscraper, wide, square, …).
- **Live progress** — while a job runs, the detail view shows fine-grained per-step
  progress and refreshes itself.
- **Prompt transparency** — every generated artifact records its complete creation
  parameters: the exact system, user and image prompts, the models, image sizes and
  voices used. They are shown in the job detail view.

## Providers, models and prompts

nr_repurpose never picks a provider itself — it names nr-llm **Configuration**
records (use cases) and lets nr-llm resolve the model, provider, API key, system
prompt and cost tracking:

| Call | nr-llm Configuration | What you can swap in the backend |
|------|----------------------|----------------------------------|
| Analysis + copy (brief, podcast script, diagram body, story copy) | the instance **default** Configuration | any chat model of any nr-llm provider: OpenAI, Anthropic Claude, Google Gemini, Groq, Mistral, Ollama, OpenRouter |
| Image generation | `nr_repurpose_image` (fallback `gpt-image-2`) | any model of nr-llm's image services (OpenAI `gpt-image-*` / `dall-e-*`; nr-llm also ships a fal.ai service — see below) |
| Text-to-speech | `nr_repurpose_tts` (fallback `tts-1`, voices `nova` + `onyx`) | any model of nr-llm's TTS service (currently OpenAI `tts-1`/`tts-1-hd`) |

System prompts (e.g. the image-style preamble) are maintained on the Configuration
records; per-model and per-configuration usage and cost show up in nr-llm's
analytics module. API keys are stored in **nr-vault** and referenced by identifier
(e.g. `nr_repurpose_openai`) — no plaintext key ever lives in extension
configuration (nr-llm ADR-030).

Honest limits today: text generation is fully provider-agnostic; image and speech
go through nr-llm's *specialized* services, which currently cover OpenAI (images,
TTS) and fal.ai (images). The extension-side seam is in place —
`ImageGeneratorInterface` / `SpeechSynthesizerInterface` with a DI alias in
`Configuration/Services.yaml` — so a fal.ai image backend is a small adapter class
away, and additional providers become available as nr-llm grows them.

## Requirements

- TYPO3 v14.3 LTS, PHP 8.3+
- nr-llm `^0.12` and nr-vault `^0.10` (installed automatically via Composer)
- An API key for at least one nr-llm-supported provider. The tested default stack
  uses a single OpenAI key for everything (analysis, TTS, images).
- `ffmpeg`, `poppler-utils` and `chromium` (+ Node.js for the renderer) on the
  host that runs the worker — baked into the DDEV web image.

## Local development (DDEV)

Prerequisites: Docker + DDEV.

```bash
cp .ddev/.env.dist .ddev/.env     # then set OPENAI_API_KEY=sk-...
ddev start                        # builds the web image (ffmpeg, poppler-utils, chromium)
ddev install                      # composer install + TYPO3 v14.3 setup into .Build/Web
```

The bundled dev wiring uses OpenAI: `ddev install` seeds the key into nr-vault
under `nr_repurpose_openai` and wires nr-llm's provider, so no further
configuration is required for a dev instance.

Backend: <https://nr-repurpose.ddev.site/typo3/> — user `admin`, password `Demo1234!`.
Open **Web › Repurpose**, choose *New job*, paste a URL, pick the artifacts, theme and
prompt snippets, and submit. The list shows the job progress; the detail view shows
live per-step progress, plays the podcast (with subtitles + transcript), shows/downloads
every image, and lists the exact prompts and models behind each artifact.

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

## Architecture

See the rendered documentation under `Documentation/` (Introduction, Installation,
Configuration, Usage, Architecture, and the Architecture Decision Records). Pipeline:
ingest (web/PDF) → analyze (one `ContentBrief` via nr-llm) → generate (podcast /
schaubild×3 / story×N slides) → store in the TYPO3 File Abstraction Layer (FAL).
