# nr_repurpose — Content Repurpose for TYPO3

Turn a webpage (URL) or PDF into three AI-generated media artifacts — a two-host
**podcast** (with transcript + subtitles), a **diagram** (Schaubild, in three variants),
and an **Instagram story** — from the TYPO3 backend. Built on
[`netresearch/nr-llm`](https://github.com/netresearch/t3x-nr-llm).

> **Status:** in active development. The async pipeline spine (job → queue → worker →
> FAL output) is complete and tested; real generators land in Plans 2–6 (see
> `docs/superpowers/plans/`).

## Local development (DDEV)

Prerequisites: Docker + DDEV. The siblings `../../t3x-nr-llm/main` and
`../../t3x-nr-vault/main` must exist (path-repo dependencies).

```bash
ddev start          # builds the web image (ffmpeg, poppler-utils, chromium)
ddev install        # composer install + TYPO3 v14.3 setup into .Build/Web
```

Backend: <https://nr-repurpose.ddev.site/typo3/> — user `admin`, password `Demo1234!`.

Open **Web › Content Studio**, choose *New job*, paste a URL, and submit. The job is
queued on the Symfony Messenger doctrine transport and processed by the worker container;
the job list shows it progress `queued → done`, and the detail view lists the artifacts.

## API keys (required for real generation)

The real generators call OpenAI (script, TTS, DALL·E) and fal.ai (images) **through
nr-llm**. Provide keys for the dev instance by copying `.ddev/.env.dist` to `.ddev/.env`:

```bash
cp .ddev/.env.dist .ddev/.env
# edit .ddev/.env: OPENAI_API_KEY=sk-...   FAL_API_KEY=...
ddev restart
```

Without keys the machinery still runs (a stub artifact is produced); no real media is
generated. Production stores keys via the nr-vault extension, not env vars.

## Tests

```bash
.Build/bin/phpunit -c Build/phpunit/UnitTests.xml                 # unit (host)
ddev exec '.Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml'  # functional (needs DDEV DB)
```

## Architecture

See `docs/superpowers/specs/2026-06-08-nr-repurpose-design.md` (design) and
`docs/superpowers/plans/` (implementation plans). The pipeline: ingest (web/PDF) →
analyze (one `ContentBrief` via nr-llm) → generate (podcast / diagram×3 / story) →
store in FAL. Long-running generation runs asynchronously via Symfony Messenger.
