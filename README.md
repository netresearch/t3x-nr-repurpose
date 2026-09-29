# nr_repurpose — Content Repurpose for TYPO3

[![CI](https://github.com/netresearch/t3x-nr-repurpose/actions/workflows/ci.yml/badge.svg)](https://github.com/netresearch/t3x-nr-repurpose/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/netresearch/t3x-nr-repurpose/graph/badge.svg)](https://codecov.io/gh/netresearch/t3x-nr-repurpose)
[![Documentation](https://github.com/netresearch/t3x-nr-repurpose/actions/workflows/docs.yml/badge.svg)](https://github.com/netresearch/t3x-nr-repurpose/actions/workflows/docs.yml)

[![OpenSSF Scorecard](https://api.securityscorecards.dev/projects/github.com/netresearch/t3x-nr-repurpose/badge)](https://securityscorecards.dev/viewer/?uri=github.com/netresearch/t3x-nr-repurpose)

[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://www.php.net/)
[![TYPO3 v14.3](https://img.shields.io/badge/TYPO3-v14.3-orange.svg)](https://typo3.org/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](LICENSE)
[![Latest Release](https://img.shields.io/github/v/release/netresearch/t3x-nr-repurpose)](https://github.com/netresearch/t3x-nr-repurpose/releases)

[![TER TYPO3](https://typo3-badges.dev/badge/nr_repurpose/typo3/shields.svg)](https://extensions.typo3.org/extension/nr_repurpose)
[![TER version](https://typo3-badges.dev/badge/nr_repurpose/version/shields.svg)](https://extensions.typo3.org/extension/nr_repurpose)

A TYPO3 extension by [Netresearch DTT GmbH](https://www.netresearch.de/).

Turn a webpage (URL) or PDF into AI-generated media artifacts — a **podcast**
with one to three persona-driven speakers (with transcript + WebVTT subtitles), a
**diagram** (Schaubild, in three variants), an **Instagram-story carousel** (optionally
also as a video) — plus four ready-to-use **texts** (executive summary, FAQ, social
posts, newsletter) and two **PDF documents** (slide deck, handout), from the TYPO3
backend.

Every AI call goes through [`netresearch/nr-llm`](https://github.com/netresearch/t3x-nr-llm):
nr_repurpose contains no provider code. Any LLM, image or TTS provider works — if
nr-llm supports it (see [Providers, models and prompts](#providers-models-and-prompts)).

## What it produces

From one source (URL or PDF) the pipeline derives a single faithful `ContentBrief`
(via nr-llm, source language auto-detected) and generates:

- **Podcast** — a dialogue between one to three speakers: select up to three
  *persona* snippets, each contributing a speaker name, a character description for
  the script and optionally its own TTS voice — or get the classic two-host default.
  Synthesized turn-by-turn via nr-llm's text-to-speech service, stitched with ffmpeg
  into one MP3, plus a speaker-tagged transcript and a WebVTT subtitle file whose cue
  times come from the measured segment durations.
- **Schaubild** — three variants for comparison: pure HTML (Fluid → headless Chromium →
  PNG), HTML with an AI-generated background, and a full AI image at the dimensions the
  selected *layout* snippet defines. Branded NR or neutral theme.
- **Story** — a multi-slide carousel (9:16 slides): a cover hook, one slide per key
  point (at most four) and an outro with the source attribution — up to six slides, one
  artifact per slide. A single optional AI background is shared by all slides — generated
  at the layout-selected dimensions and scaled to *cover* the design canvas so the
  layout is never distorted.
- **Story video** — with *Also as video*, the finished slides also become one silent
  1080×1920 MP4: each slide zooms in slowly for four seconds and cross-fades into the
  next (ffmpeg, no further AI call). It is made from the story of the same run, so it
  needs the story.
- **Text formats** — an executive summary (5–8 sentences asked for, key facts first), an
  FAQ (5–10 pairs from the source only asked for — more are cut, fewer kept as they come —
  plus schema.org `FAQPage` JSON-LD), one social post
  each for LinkedIn (≤ 3000 characters), X (≤ 280) and Instagram (caption + hashtags,
  ≤ 2200), and a newsletter text (subject, preheader, paragraphs, one call to action).
  Each is one schema-validated LLM call; the platform limits are enforced in code by
  cutting at a sentence boundary (or a word boundary when that keeps more). The text formats are off by default — tick them per job.
- **Documents (PDF)** — a 16:9 **slide deck** (title slide, three to eight content
  slides with up to five bullet points, closing slide with the takeaway) and a one- to
  two-page A4 **handout** (title, lead, up to five sections, a box with up to six key
  facts). Each is one schema-validated LLM call like a text format, printed to PDF by
  the same headless Chromium that renders the images; off by default.

Each artifact type can be selected per run. Long-running generation runs asynchronously
via Symfony Messenger (doctrine transport).

## Editorial steering and transparency

- **Prompt snippets** — the job form offers *audience*, *tone of voice*, *persona*,
  *layout* and *style* selectors, populated from nr-llm's prompt-snippet library
  (each option shows its description). A layout snippet's `imageSize` metadata
  drives the AI-image dimensions per channel (skyscraper, wide, square, …).
  A fresh installation has no snippets, so install the **Content Repurpose
  Starter** pack to fill the five selectors — from nr-llm's Use Case Packs
  module, or `vendor/bin/typo3 nrllm:usecasepack:install content-repurpose-starter`.
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
| Analysis + copy (brief, podcast script, diagram body, story copy, text formats) | the instance **default** Configuration (import the `nr_repurpose_text` preset and mark it default) | any chat model of any nr-llm provider: OpenAI, Anthropic Claude, Google Gemini, Groq, Mistral, Ollama, OpenRouter |
| Image generation | `nr_repurpose_image` (fallback `gpt-image-2`) | any model of nr-llm's image services (OpenAI `gpt-image-*` / `dall-e-*`; nr-llm also ships a fal.ai service — see below) |
| Text-to-speech | `nr_repurpose_tts` (fallback `tts-1`; default voices `nova` + `onyx`, persona snippets can set their own voice per speaker) | any model of nr-llm's TTS service (currently OpenAI `tts-1`/`tts-1-hd`) |

You do not create these records by hand: nr_repurpose **declares them as
configuration presets** (nr-llm ADR-056). Open nr-llm's **Configurations** backend
module — the three `nr_repurpose_*` records appear as *pending presets* with their
required capabilities, and a single click imports each as a criteria-mode
configuration that resolves against the models you have. Mark the imported
`nr_repurpose_text` record as the instance default for the analysis/copy calls.

System prompts (e.g. the image-style preamble) are maintained on the Configuration
records; per-model and per-configuration usage and cost show up in nr-llm's
analytics module. API keys belong to **nr-llm** and are referenced by identifier
(e.g. `nr_repurpose_openai`) — no plaintext key ever lives in extension
configuration (nr-llm ADR-030).

Honest limits today: text generation is fully provider-agnostic; image and speech
go through nr-llm's *specialized* services, which currently cover OpenAI (images,
TTS) and fal.ai (images). The extension-side seam is in place —
`ImageGeneratorInterface` / `SpeechSynthesizerInterface` with a DI alias in
`Configuration/Services.yaml` — so a fal.ai image backend is a small adapter class
away, and additional providers become available as nr-llm grows them.

## Full control — no black box

nr_repurpose is not a SaaS pipeline you feed content into and hope for the best.
It runs entirely inside your TYPO3 instance, and through nr-llm every aspect of
the AI usage stays under the operator's control:

- **Provider sovereignty** — decide per use case which provider serves it: a US
  cloud, an EU provider (e.g. Mistral), or fully self-hosted models via Ollama,
  where content never leaves your infrastructure. Switching is a backend record
  edit, not a deployment.
- **Costs** — per-user budgets are enforced by nr-llm's middleware; every call is
  metered and attributed per model and per configuration in nr-llm's analytics
  module; image and speech calls are additionally pre-gated against the budget
  with a planned cost before any money is spent.
- **Prompts** — system prompts are maintained centrally on Configuration records,
  editorial steering on reviewable prompt snippets, and every artifact stores the
  exact prompts, models, sizes and voices that produced it — reproducible and
  reviewable after the fact.
- **Auditing** — nr-llm keeps the API keys encrypted and routes every outbound
  AI call through an audited client: a who/what/when trail exists for each one.
- **Permissions** — backend group permissions gate which editors may spend on
  audio (`generate_audio`) and AI imagery (`generate_vision`).

## Requirements

- TYPO3 v14.3 LTS, PHP 8.3+
- nr-llm `^0.35 || ^0.36 || ^0.37 || ^0.38` (installed automatically via Composer; it owns the provider
  credentials)
- An API key for at least one nr-llm-supported provider. The tested default stack
  uses a single OpenAI key for everything (analysis, TTS, images).
- `ffmpeg`, `poppler-utils` and `chromium` (+ Node.js for the renderer) on the
  host that runs the worker — baked into the DDEV web image.

## Installation

### Composer

```bash
composer require netresearch/nr-repurpose
vendor/bin/typo3 extension:setup nr_repurpose
```

Composer pulls in nr-llm and nr-vault. Then hand the provider key to nr-llm, import
the three `nr_repurpose_*` configuration presets, and run a Messenger worker — see
the [Installation](Documentation/Installation/Index.rst) and
[Configuration](Documentation/Configuration/Index.rst) chapters.

### Composer only — no classic mode

> [!WARNING]
> nr_repurpose requires a Composer-based TYPO3 installation. Installing it through
> the Extension Manager (classic mode) is not supported.

PDF ingestion needs the PHP library `smalot/pdfparser`, and the TER package contains
no `vendor/` directory. The Node renderer needs `playwright-core`, installed from
`Resources/Private/NodeRenderer/package.json` and `package-lock.json`, and the TER
package ships only `render.cjs`. The
[TER entry](https://extensions.typo3.org/extension/nr_repurpose) exists so the
extension can be found there; install it with Composer.

### Verifying a release

Every GitHub release carries the extension as `nr-repurpose-X.Y.Z.zip` and
`nr-repurpose-X.Y.Z.tar.gz` with a signed SLSA build provenance attestation. The
release is built by the shared workflow in `netresearch/typo3-ci-workflows`, so
the verification names that repository as the signer:

```bash
gh attestation verify nr-repurpose-X.Y.Z.zip \
  --repo netresearch/t3x-nr-repurpose \
  --signer-repo netresearch/typo3-ci-workflows
```

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
schaubild×3 / story×N slides / text formats) → store in the TYPO3 File Abstraction Layer (FAL); the
text formats write no file, their text lives on the artifact row.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Credits

Developed and maintained by [Netresearch DTT GmbH](https://www.netresearch.de/).

Copyright (c) 2025-2026 [Netresearch DTT GmbH](https://www.netresearch.de/).
