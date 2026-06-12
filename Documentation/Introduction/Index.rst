.. include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

.. _introduction-what-it-does:

What does it do?
================

Content Repurpose (``nr_repurpose``) turns a single source — a webpage URL or a
PDF — into three AI-generated media artifacts, all from the TYPO3 backend:

#. a **podcast** (audio) with one to three persona-driven speakers,
#. a **Schaubild** (diagram/infographic), rendered in three variants, and
#. a 9:16 **Instagram story** carousel (one image per slide).

It is a thin orchestration layer on top of :composer:`netresearch/nr-llm`: the
LLM access (chat/vision completions, text-to-speech, image generation) and the
per-user budget enforcement all come from nr-llm — any provider nr-llm supports
can be used, selected in nr-llm's backend module rather than in code. The
provider API keys are stored in :composer:`netresearch/nr-vault` and read by
identifier, so no plaintext key lives in this extension. nr_repurpose adds the
ingestion, the prompting, the local rendering toolchain, and the backend module
that ties them together.

From one source it first derives exactly one faithful :php:`ContentBrief`
(title, summary, key points, sections, audience, detected language) and then
feeds that brief to each selected generator.

.. _introduction-the-three-artifacts:

The three artifacts
===================

.. _introduction-podcast:

Podcast
-------

A lively dialogue between one to three speakers. When the job selects *persona*
prompt snippets (up to three), each persona contributes its speaker name, a
character description woven into the script prompt, and optionally its own TTS
voice from the snippet metadata; without personas the classic default applies —
*Host A* (voice ``nova``) and *Host B* (voice ``onyx``). nr-llm's
:php:`CompletionService` writes the script as turns spoken by these speakers;
each turn is synthesized to an MP3 segment via nr-llm's text-to-speech service,
the segments are concatenated with ``ffmpeg``, and a speaker-tagged transcript
plus a WebVTT subtitle file (whose cue times come from the measured segment
durations, read with ``ffprobe``) are produced alongside the audio.

.. _introduction-schaubild:

Schaubild (diagram)
-------------------

Three variants of the same diagram are produced for comparison:

-   ``html`` — an LLM-built branded HTML fragment, rendered opaque to PNG by
    headless Chromium. Labels are exactly correct; this is the reference variant.
-   ``html_bg`` — an AI-generated background image with the same HTML rendered
    transparent and composited on top (GD).
-   ``ki_image`` — a full AI text-to-image rendering from a content-derived
    prompt.

The branded (``nr``) or neutral theme is chosen per job.

.. _introduction-story:

Instagram story
---------------

A multi-slide 1080×1920 (9:16) carousel: a cover slide (hook/title), one slide
per key point (at most four), and an outro slide with the takeaway and the
source attribution — at most six slides per run. One LLM call writes the copy
for all slides; each slide becomes its own artifact (variant ``slide-1`` …
``slide-N``), so a failing slide does not affect its siblings. When the image
service is available and within budget, a single AI background is generated
once — at the dimensions the selected *layout* snippet defines — and shared by
every slide (visual coherence, one image cost), scaled to *cover* the canvas
(centre-cropped, never distorted), with the transparent text layer composited
over it; otherwise flat branded renders are used.

Each artifact type is opt-in per run (``want_podcast`` / ``want_schaubild`` /
``want_story``). Per-artifact failures are isolated — one failing generator does
not abort its siblings.

.. _introduction-foundation:

The nr-llm / nr-vault foundation
================================

nr_repurpose never talks to an AI provider directly. Every AI call goes through
nr-llm:

-   **Analysis and copy** (the brief, the podcast script, the diagram body, the
    story copy) use nr-llm's :php:`CompletionService`, which resolves the
    instance's default nr-llm Configuration — and with it any chat provider
    nr-llm supports (OpenAI, Anthropic Claude, Google Gemini, Groq, Mistral,
    Ollama, OpenRouter) — and is guarded by nr-llm's budget middleware via a
    backend-user uid.
-   **Text-to-speech and image generation** use nr-llm's specialized services
    (:php:`TextToSpeechService`, :php:`DallEImageService`), with the model
    resolved through the nr-llm Configuration records ``nr_repurpose_tts`` and
    ``nr_repurpose_image``. These are not middleware-guarded, so nr_repurpose
    gates them manually with nr-llm's :php:`BudgetService` and each service's
    ``isAvailable()`` check before spending.

The provider keys are held by nr-vault and resolved by nr-llm by identifier
(e.g. ``nr_repurpose_openai``); the secret is injected, audited, and
memory-scrubbed inside the vault and never surfaces in nr_repurpose. See
:ref:`adr-003`.

.. _introduction-control:

Full control — no black box
===========================

nr_repurpose is not a SaaS pipeline you feed content into: it runs entirely
inside the TYPO3 instance, and through nr-llm every aspect of the AI usage
stays under the operator's control —

-   **Provider sovereignty.** Decide per use case which provider serves it: a
    US cloud, an EU provider (e.g. Mistral), or fully self-hosted models via
    Ollama, where content never leaves your infrastructure. Switching is a
    backend record edit, not a deployment.
-   **Costs.** Per-user budgets are enforced by nr-llm's middleware; every call
    is metered and attributed per model and per configuration in nr-llm's
    analytics module; image and speech calls are pre-gated against the budget
    with a planned cost before any spend.
-   **Prompts.** System prompts live centrally on Configuration records,
    editorial steering on reviewable prompt snippets, and every artifact stores
    the exact prompts, models, sizes and voices that produced it.
-   **Auditing.** Keys are envelope-encrypted in nr-vault; every key use goes
    through its audited secure HTTP client — a who/what/when trail for every
    outbound AI call.
-   **Permissions.** Backend group permissions gate who may spend on audio and
    AI imagery (see :ref:`configuration-permissions`).

.. _introduction-requirements:

Requirements
============

-   **PHP** 8.3 or higher.
-   **TYPO3** v14.3 LTS.
-   :composer:`netresearch/nr-llm` and :composer:`netresearch/nr-vault`
    (installed via Composer).
-   An **API key for at least one nr-llm-supported provider**. The tested
    default stack uses a single OpenAI key for everything (chat/vision
    analysis, TTS, ``gpt-image-2`` images); text generation can equally use any
    other nr-llm chat provider, while image and speech currently require a
    provider covered by nr-llm's specialized services (OpenAI; fal.ai for
    images).
-   The system binaries ``poppler-utils`` (PDF), ``ffmpeg`` / ``ffprobe``
    (audio), and ``chromium`` (rendering), plus a Node.js runtime for the
    renderer.

See :ref:`installation` for the full list and setup.

.. _introduction-credits:

Credits
=======

This extension is developed and maintained by **Netresearch DTT GmbH**
(https://www.netresearch.de).
