.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

.. _introduction-what-it-does:

What does it do?
================

Content Repurpose (``nr_repurpose``) turns a single source — a webpage URL or a
PDF — into AI-generated media artifacts and texts, all from the TYPO3 backend:

#. a **podcast** (audio) with one to three persona-driven speakers,
#. a **Schaubild** (diagram/infographic), rendered in three variants,
#. a 9:16 **Instagram story** carousel (one image per slide), optionally also
   as a video,
#. four **text formats**: an executive summary, an FAQ, social-media posts and
   a newsletter text, and
#. two **documents** as PDF: a 16:9 slide deck and a printable A4 handout.

It is a thin orchestration layer on top of :composer:`netresearch/nr-llm`: the
LLM access (chat/vision completions, text-to-speech, image generation) and the
per-user budget checks come from nr-llm. The available providers depend on
the operation and the adapter that Repurpose uses. The
provider API keys belong to nr-llm and are referenced only by identifier, so no
plaintext key lives in this extension. nr_repurpose adds the
ingestion, the prompting, the local rendering toolchain, and the backend module
that ties them together.

From one source it first derives exactly one faithful :php:`ContentBrief`
(title, summary, key points, sections, audience, detected language) and then
feeds that brief to each selected generator.

.. _introduction-the-three-artifacts:

The artifacts
=============

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

With *Also as video*, the finished slides also become one silent MP4 of
1080×1920: each slide zooms in slowly for four seconds and cross-fades into the
next (ffmpeg, no further AI call). The video is made from the story of the same
run, so it needs the story.

.. _introduction-text-formats:

Text formats
------------

Four ready-to-use texts, each written by one structured LLM call from the same
brief and answered in the source language. They are off by default in the job
form, so an existing installation makes no additional LLM calls until an editor
selects one:

-   **Executive summary** — the model is asked for five to eight sentences for
    decision makers, key facts first; more than eight are cut, fewer are kept
    as they come.
-   **FAQ** — the model is asked for five to ten question/answer pairs taken
    only from the source; more than ten are cut, fewer are kept as they come.
    Shown as a list and additionally as schema.org ``FAQPage`` JSON-LD to paste
    into a page.
-   **Social posts** — one post each for LinkedIn (up to 3,000 characters), X
    (up to 280) and Instagram (a caption with hashtags, up to 2,200). The limits
    are enforced in code: a longer post is cut at the last sentence end that
    fits, or at a word boundary when that would keep less than half the post.
-   **Newsletter** — subject line, preheader, a body in plain paragraphs and
    one call to action.

See :ref:`usage-text-formats` for what each format stores and how the limits
are counted.

.. _introduction-documents:

Documents
---------

Two formats that leave the backend as a PDF. Each is written like a text
format — one structured LLM call, off by default — and then printed by the same
headless Chromium that renders the images (see :ref:`adr-006`):

-   **Slide deck** — a 16:9 presentation: a title slide, three to eight content
    slides with a heading and up to five bullet points, and a closing slide with
    the takeaway.
-   **Handout** — one to two A4 pages: title, lead, up to five sections and a
    box with up to six key facts.

Each artifact type is opt-in per run (``want_podcast`` / ``want_schaubild`` /
``want_story`` / ``want_exec_summary`` / ``want_faq`` / ``want_social_post`` /
``want_newsletter`` / ``want_slide_deck`` / ``want_handout``, and ``want_video``
for the story video). Per-artifact failures are isolated — one failing generator
does not abort its siblings.

.. _introduction-foundation:

The nr-llm foundation
=====================

nr_repurpose never talks to an AI provider directly. Every AI call goes through
nr-llm:

-   **Analysis and copy** (the brief, the podcast script, the diagram body, the
    story copy and the text formats) use the ``nr_repurpose_text`` configuration.
    If the worker cannot resolve that record, page jobs follow the site
    setting and jobs without a site override use the instance default. An
    invalid site override fails the job. Check the effective endpoints and
    fallback providers before processing sensitive source material
    (see :ref:`configuration-site`).
    The text service supports nr-llm chat providers and passes the backend
    user ID to nr-llm's budget middleware.
-   **Text-to-speech and image generation** use nr-llm's specialized services
    (:php:`TextToSpeechService`, :php:`DallEImageService`), with the model
    resolved through the nr-llm Configuration records ``nr_repurpose_tts`` and
    ``nr_repurpose_image``. Before starting generation, nr_repurpose checks the
    job creator's budget with nr-llm's :php:`BudgetService` and checks that
    the relevant service is available.

The provider keys belong to nr-llm, which resolves them by identifier (e.g.
``nr_repurpose_openai``) and injects them into the outbound call. No secret
surfaces in nr_repurpose. See :ref:`adr-003`.

.. _introduction-control:

Where content is processed
==========================

The TYPO3 worker runs the job, renders files and stores the results. Analysis
and copy send source material to the configured chat endpoint, which can be
self-hosted. Generated artwork sends image prompts to the image service. A
podcast sends the speaker text to the speech service. A scanned PDF can also
require a vision request.

The bundled adapters use nr-llm's OpenAI image and text-to-speech services.
Selecting a local chat model leaves those adapters in place. For an endpoint
supporting the same image or speech API, set ``image.dalle.baseUrl`` or
``speech.tts.baseUrl`` in nr-llm. Services using a different protocol need a
corresponding adapter in :file:`Configuration/Services.yaml`.

-   **Costs.** The generator checks the job creator's budget against an estimated
    cost before starting speech or image generation. Running jobs can overlap.
    Use the provider invoice to check actual spend.
-   **Prompts.** System prompts live centrally on Configuration records,
    editorial steering on reviewable prompt snippets, and every artifact stores
    the exact prompts, models, sizes and voices that produced it.
-   **Usage records.** nr-llm records usage after the provider call. A provider
    response can succeed even if writing its usage record fails.
-   **Permissions.** Backend group permissions gate who may spend on audio and
    AI imagery (see :ref:`configuration-permissions`).

.. _introduction-requirements:

Requirements
============

-   **PHP** 8.3 or higher.
-   **TYPO3** v14.3 LTS.
-   :composer:`netresearch/nr-llm` (installed via Composer, with its own
    dependencies).
-   An **API key for at least one nr-llm-supported provider**. The tested
    default stack uses a single OpenAI key for everything (chat/vision
    analysis, TTS, ``gpt-image-2`` images); text generation can equally use any
    other compatible chat providers. The bundled adapters for AI images and
    speech use nr-llm's OpenAI services.
-   The system binaries ``poppler-utils`` (PDF), ``ffmpeg`` / ``ffprobe``
    (audio), and ``chromium`` (rendering), plus a Node.js runtime for the
    renderer.

See :ref:`installation` for the full list and setup.

.. _introduction-credits:

Credits
=======

This extension is developed and maintained by **Netresearch DTT GmbH**
(https://www.netresearch.de).
