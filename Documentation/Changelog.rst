.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

All notable changes to Content Repurpose (``nr_repurpose``) are documented here.

The format follows `Keep a Changelog <https://keepachangelog.com/>`_ and the
project adheres to `Semantic Versioning <https://semver.org/>`_.

.. _version-0-1-0:

Version 0.1.0
=============

Initial alpha release.

Added
-----

-   **Three artifact generators.** From one source (URL or PDF) the pipeline
    derives a single :php:`ContentBrief` via nr-llm and generates a
    persona-aware podcast with one to three speakers (TTS + ffmpeg stitch +
    WebVTT subtitles), a Schaubild diagram in
    three variants (HTML, HTML-with-AI-background, full AI image), and a
    multi-slide 9:16 Instagram story carousel.

-   **Prompt-snippet steering.** Persona, tone, audience, image-style and
    layout selectors in the job form, backed by nr-llm's prompt-snippet
    library; layout snippets carry an ``imageSize`` metadata key that drives
    gpt-image-2 output dimensions per channel.

-   **Live progress and prompt transparency.** The job detail view shows
    fine-grained per-step progress with auto-refresh while a job runs and,
    for every artifact, the complete creation parameters: the exact system,
    user and image prompts, models, image sizes and voices.

-   **Central usage and cost tracking.** Image and speech models resolve
    through nr-llm Configuration records (``nr_repurpose_image``,
    ``nr_repurpose_tts``), so every call is attributed per model and per
    configuration in the nr-llm analytics module.

-   **Ingestion.** URL fetch with deterministic DOM main-content extraction, and
    a tiered PDF reader (embedded text → Vision OCR for sparse pages → poppler
    layout extraction for tabular pages), selectable per job via ``pdf_mode``.

-   **Asynchronous generation.** Job submission dispatches a
    :php:`GenerateArtifactsMessage` onto a Symfony Messenger transport; a
    long-running worker consumes it and runs the orchestrator. See
    :ref:`adr-001`.

-   **Node + Playwright renderer.** A bundled CommonJS script
    (``render.cjs``) drives the apt ``chromium`` binary through
    ``playwright-core`` to turn branded Fluid HTML into PNGs. See :ref:`adr-002`.

-   **Backend module.** *Repurpose* (``web_nrrepurpose``) with a job list,
    a submission form, and a result view that plays the podcast and shows every
    generated image.

-   **CLI command.** ``nr_repurpose:generate <jobUid>`` runs the full pipeline
    synchronously for ops and debugging.

-   **Vault-backed OpenAI key.** The OpenAI key is stored in nr-vault and read
    by identifier (``nr_repurpose_openai``) by nr-llm. See :ref:`adr-003`.
