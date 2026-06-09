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

#. a two-host **podcast** (audio),
#. a **Schaubild** (diagram/infographic), rendered in three variants, and
#. a 9:16 **Instagram story** image.

It is a thin orchestration layer on top of :composer:`netresearch/nr-llm`: the
LLM access (chat/vision completions, text-to-speech, image generation) and the
per-user budget enforcement all come from nr-llm. The OpenAI API key is stored
in :composer:`netresearch/nr-vault` and read by identifier, so no plaintext key
lives in this extension. nr_repurpose adds the ingestion, the prompting, the
local rendering toolchain, and the backend module that ties them together.

From one source it first derives exactly one faithful :php:`ContentBrief`
(title, summary, key points, sections, audience, detected language) and then
feeds that brief to each selected generator.

.. _introduction-the-three-artifacts:

The three artifacts
===================

.. _introduction-podcast:

Podcast
-------

A lively two-host dialogue. nr-llm's :php:`CompletionService` writes the script
as turns spoken by *Host A* (voice ``nova``) and *Host B* (voice ``onyx``); each
turn is synthesized to an MP3 segment via nr-llm's text-to-speech service, the
segments are concatenated with ``ffmpeg``, and a speaker-tagged transcript plus
a WebVTT subtitle file (whose cue times come from the measured segment
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

A single 1080×1920 (9:16) slide condensed to a headline and subline. When the
image service is available and within budget, an AI background is generated and
scaled to *cover* the canvas (centre-cropped, never distorted), with the
transparent text layer composited over it; otherwise a flat branded render is
used.

Each artifact type is opt-in per run (``want_podcast`` / ``want_schaubild`` /
``want_story``). Per-artifact failures are isolated — one failing generator does
not abort its siblings.

.. _introduction-foundation:

The nr-llm / nr-vault foundation
================================

nr_repurpose does not talk to OpenAI directly. Every AI call goes through
nr-llm:

-   **Analysis and copy** (the brief, the podcast script, the diagram body, the
    story copy) use nr-llm's :php:`CompletionService`, which is guarded by
    nr-llm's budget middleware via a backend-user uid.
-   **Text-to-speech and image generation** use nr-llm's specialized services
    (:php:`TextToSpeechService`, :php:`DallEImageService`). These are not
    middleware-guarded, so nr_repurpose gates them manually with nr-llm's
    :php:`BudgetService` and each service's ``isAvailable()`` check before
    spending.

The OpenAI key is held by nr-vault and resolved by nr-llm by identifier
(``nr_repurpose_openai``); the secret is injected, audited, and memory-scrubbed
inside the vault and never surfaces in nr_repurpose. See :ref:`adr-003`.

.. _introduction-requirements:

Requirements
============

-   **PHP** 8.3 or higher.
-   **TYPO3** v14.3 LTS.
-   :composer:`netresearch/nr-llm` and :composer:`netresearch/nr-vault`
    (installed via Composer).
-   An **OpenAI API key** (chat/vision for analysis, TTS for the podcast,
    ``gpt-image-1`` for images). No other provider key is required.
-   The system binaries ``poppler-utils`` (PDF), ``ffmpeg`` / ``ffprobe``
    (audio), and ``chromium`` (rendering), plus a Node.js runtime for the
    renderer.

See :ref:`installation` for the full list and setup.

.. _introduction-credits:

Credits
=======

This extension is developed and maintained by **Netresearch DTT GmbH**
(https://www.netresearch.de).
