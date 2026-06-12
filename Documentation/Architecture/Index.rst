.. include:: /Includes.rst.txt

.. _architecture:

============
Architecture
============

This section describes how nr_repurpose turns one source into three artifacts,
and how it composes nr-llm's capabilities with a local rendering toolchain.

.. contents::
   :local:
   :depth: 2

.. _architecture-overview:

Pipeline overview
================

A job moves through four stages. Submission only enqueues work; everything below
the dashed line runs in the worker process:

::

   Backend "New job" form / CLI
            │  persist Job, dispatch GenerateArtifactsMessage(jobUid)
            ▼
   ┌─────────────────────────── Symfony Messenger (doctrine) ───────────────────────────┐
   │  worker: messenger:consume                                                          │
   │                                                                                     │
   │   1. INGEST    SourceIngestionService   URL fetch | tiered PDF read  → SourceDocument│
   │   2. ANALYZE   DocumentAnalyzer (nr-llm CompletionService, JSON)     → ContentBrief  │
   │   3. GENERATE  PodcastGenerator | SchaubildGenerator | StoryGenerator               │
   │                  └─ nr-llm completion / TTS / image + local render → PNG/MP3/VTT     │
   │   4. STORE     JobFileStorage → FAL (repurpose/ folder), Artifact rows updated      │
   │                                                                                     │
   │   status: queued → ingesting → analyzing → generating → done|partially_done|failed  │
   └─────────────────────────────────────────────────────────────────────────────────────┘

The orchestrator (:php:`GenerationOrchestrator::process()`) drives the worker
side. It is idempotent — a job already in a terminal status is never reprocessed
— and it aborts the whole run if ingestion or analysis fails, but isolates
per-artifact failures in the generation stage.

.. _architecture-submission:

Submission and the async boundary
================================

The backend ``create`` action and the :php:`JobSubmissionService` persist the
:php:`Job` Extbase entity, flush persistence to obtain its uid, and dispatch an
immutable :php:`GenerateArtifactsMessage` carrying **only** the job uid — the
worker re-reads all inputs from the database, so the message stays minimal and
the job row is the single source of truth.

The message is routed to the doctrine transport (see
:ref:`configuration-messenger`). Because TYPO3 v14.3 Core ships no retry/failure
transport, the :php:`GenerateArtifactsHandler` catches any throwable from the
orchestrator, marks the job failed, and does not rethrow — a crash is recorded
on the job rather than lost. See :ref:`adr-001`.

.. _architecture-ingestion:

Stage 1 — Ingestion
==================

:php:`SourceIngestionService` is the single entry point and dispatches on the
job's ``source_type``:

-   **URL** — :php:`WebPageFetcher` fetches the page over a PSR-18 client,
    strips boilerplate node types (``script``, ``nav``, ``footer``, …) and HTML
    comments, then keeps the densest of ``<article>`` / ``<main>`` (falling back
    to ``<body>``) and collapses whitespace.
-   **PDF** (URL or FAL) — :php:`PdfFileResolver` resolves a local absolute
    path, then each page is read through a per-page tier dispatcher honouring
    ``pdf_mode``:

    .. list-table::
       :header-rows: 1
       :widths: 20 80

       * - Mode
         - Per-page behaviour
       * - ``auto``
         - Tier 1 embedded text; a sparse page falls back to Tier 2 Vision OCR;
           a page that looks tabular uses Tier 3 layout extraction.
       * - ``text``
         - Tier 1 (embedded text) for every page.
       * - ``vision``
         - Tier 2 (Vision OCR via nr-llm) for every page.
       * - ``tables``
         - Tier 3 (poppler layout extraction) for every page.

Both branches produce a :php:`SourceDocument` value object (title, text, source
label, page count, language hint, meta). An empty result raises an
:php:`IngestionException`, which aborts the job before any artifact is created.

.. _architecture-analysis:

Stage 2 — Understanding
=====================

:php:`DocumentAnalyzer` produces exactly one :php:`ContentBrief` from the
:php:`SourceDocument` using nr-llm's :php:`CompletionService` in JSON mode. The
brief holds the title, summary, key points, sections, audience, and the detected
source language (an ISO-639-1 code returned by the model, with the document
language hint as fallback). The detected language is recorded on the job for the
result view, and every downstream generator writes its copy in that language.

For large documents (above the analyzer's chunk threshold, 24 000 characters
by default) the analyzer uses
map-reduce: it splits the text on paragraph boundaries into chunks, summarizes
each chunk (map), and synthesizes one brief from the concatenated summaries
(reduce), to stay within provider token limits. Each completion call carries the
backend-user uid so nr-llm's budget middleware can enforce the user's budget.

.. _architecture-generation:

Stage 3 — Generation
===================

The orchestrator collects the generators tagged ``nr_repurpose.artifact_generator``
(podcast, Schaubild, story), filters them by ``supports()`` against the job's
``want_*`` flags, and runs each one with a shared per-run :php:`GenerationContext`
(job row, document, brief, theme, backend user). A generator records its own
artifact rows and returns a boolean; one failing generator never aborts the
others. The final job status is ``done`` (all succeeded), ``partially_done``
(some), or ``failed`` (none). All generators extend :php:`AbstractGenerator`,
which provides Fluid theme rendering (v14 ViewFactory), per-run temp
directories, the failed-artifact helper, and the specialized-call budget guard.

.. _architecture-generation-budget:

Two AI cost paths
-----------------

nr-llm guards *completion* calls with its budget middleware automatically (via
the ``beUserUid`` on :php:`ChatOptions`). Its *specialized* services (TTS,
image) are **not** middleware-guarded, so before spending on them each generator
calls :php:`AbstractGenerator::specializedAllowed()`, which checks nr-llm's
:php:`BudgetService` and the service's ``isAvailable()``. A budget-starved run
therefore still yields the cost-free variants (for example the deterministic
HTML Schaubild) while skipping the AI-image variants.

.. _architecture-generation-podcast:

Podcast
-------

:php:`PodcastGenerator` asks the completion service for a dialogue script sized
to the document scope, spoken by the job's selected personas (one to three, each
with its name, character description and optional own TTS voice) or by the
default hosts (Host A = ``nova``, Host B = ``onyx``). Each
turn is one specialized TTS call producing an MP3 segment, with a single retry
on a transient failure and a skip (rather than a whole-episode failure) if a
turn still fails. The segments are concatenated by
:php:`FfmpegAudioStitcher::concat()` (ffmpeg ``concat`` demuxer, stream copy, no
re-encode); per-segment durations are read with ``ffprobe`` and fed to
:php:`WebVttBuilder` so the subtitle cue times match the audio. The MP3 and the
``.vtt`` are stored in FAL; the speaker-tagged transcript is kept on the
artifact row.

.. _architecture-generation-schaubild:

Schaubild
---------

:php:`SchaubildGenerator` produces three artifact rows for empirical comparison:

-   ``html`` — the LLM writes a branded HTML diagram body; it is wrapped in the
    theme template and rendered opaque to PNG by Chromium. No specialized call,
    so this variant always proceeds.
-   ``html_bg`` — an AI background image plus the same diagram rendered
    transparent, composited together (see :ref:`architecture-rendering`).
-   ``ki_image`` — a full AI text-to-image from a content-derived prompt.

The diagram is rendered at 1200 px wide, auto-height.

.. _architecture-generation-story:

Instagram story
---------------

:php:`StoryGenerator` asks the completion service once for the whole carousel —
a cover slide, one slide per key point (at most four) and an outro with the
source attribution, capped at six slides; the planned cost scales with the
expected slide count. Each slide is rendered from the branded 9:16 template
(1080×1920) into its own artifact row (variant ``slide-N``; slide role, index
and total in the metadata), so a failed slide render fails only that slide.
When the image service is available and within budget one portrait AI
background is generated and composited behind every slide; otherwise the
slides fall back to flat renders.

.. _architecture-rendering:

Rendering toolchain
===================

Three render primitives sit behind interfaces (so they are swappable and
testable) and shell out through a :php:`ProcessRunnerInterface` (Symfony
Process):

-   **HTML → PNG** — :php:`PlaywrightHtmlToImageRenderer` drives the bundled
    ``render.cjs`` (``playwright-core`` + the apt ``chromium`` binary). HTML is
    fed on **stdin** (avoiding argv limits and shell quoting); ``CHROMIUM_PATH``
    is exported into the process environment. ``height=null`` renders
    full-page/auto-height (the diagram); a fixed height clips to the viewport
    (the story). ``transparent`` uses ``omitBackground`` for the overlay layers.
-   **Compositing** — :php:`GdImageCompositor` overlays a transparent foreground
    PNG (the exact text/label layer) onto a background PNG (the AI image) using
    GD (Imagick is not in the stack). The **foreground** defines the output
    canvas; the background is requested at a layout-matching size where the
    image model allows it (``gpt-image-2`` accepts arbitrary dimensions within
    its aspect limits), but whenever the aspect ratios still differ it is
    scaled to *cover* (centre-cropped, no distortion), then the foreground is
    alpha-composited on top so transparent areas reveal the background.
-   **Audio** — :php:`FfmpegAudioStitcher` (concat demuxer + ``ffprobe`` for
    durations), described above.

See :ref:`adr-002` for why image composition runs through a Node + Playwright
renderer rather than a PHP imaging library.

.. _architecture-storage:

Stage 4 — Storage and result
===========================

Generated bytes are written to the default FAL storage under a ``repurpose/``
folder by :php:`JobFileStorage`, which returns a ``sys_file``; the artifact rows
reference files by ``sys_file`` uid (audio as ``file_uid``, subtitles as
``subtitle_file_uid``). The backend result view reads these references to play
the podcast and display every image. Because each artifact tracks its own status
and FAL references, a partially successful run is fully renderable.

.. _architecture-nr-llm:

How nr-llm capabilities are composed
====================================

nr_repurpose owns no provider code. It depends on three nr-llm surfaces:

-   :php:`CompletionService` — the brief, the podcast script, the diagram body,
    and the story copy (JSON or Markdown responses, budget-middleware guarded).
-   :php:`TextToSpeechService` — wrapped by :php:`OpenAiSpeechSynthesizer` behind
    a local :php:`SpeechSynthesizerInterface` (model resolved through the
    ``nr_repurpose_tts`` nr-llm Configuration, fallback ``tts-1``).
-   :php:`DallEImageService` — wrapped by :php:`DallEImageGenerator` behind a
    local :php:`ImageGeneratorInterface` (model resolved through the
    ``nr_repurpose_image`` nr-llm Configuration, fallback ``gpt-image-2``).

The two specialized wrappers are thin adapters: they expose ``isAvailable()``
and a single ``…ToFile()`` method, and translate nr-llm exceptions into the
extension's :php:`RenderingException`. This keeps the generators independent of
nr-llm's concrete service shapes, lets unit tests substitute fakes, and is the
seam for additional image/speech backends (the DI aliases live in
:path:`Configuration/Services.yaml`). The provider keys behind all of these are
resolved from nr-vault by identifier (see :ref:`adr-003`).
