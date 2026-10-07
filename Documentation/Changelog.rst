.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

All notable changes to Content Repurpose (``nr_repurpose``) are documented here.

The format follows `Keep a Changelog <https://keepachangelog.com/>`_ and the
project adheres to `Semantic Versioning <https://semver.org/>`_. This page
lists the main points of each release; the full entries, with the reasoning
behind each change, are in the repository's
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-repurpose/blob/main/CHANGELOG.md>`__.

.. _version-0-10-0:

Version 0.10.0 (2026-10-07)
===========================

Upgrade notes
-------------

-   **Installations that sign their social posts** move the signing secret
    to nr-vault: 0.10.0 no longer reads the extension setting
    ``socialWebhookSecret``, and while it still holds a value no post is sent.
    Before updating, store the secret in nr-vault through its backend module
    (on the command line, ``vault:store`` works only with ``--as-provisioner``
    and nr-vault's ``provisioningBeUserUid`` set, or with its CLI access on),
    and make sure ``nr_repurpose:publish-due`` can read it: with
    ``technicalBeUserUid`` set it reads as that backend user, who needs read
    access to the secret; with ``technicalBeUserUid`` at 0 it reads as the
    ``_cli_`` administrator under ``scheduler:run`` and needs nr-vault's CLI
    access when started from cron. After updating, enter the identifier in the new setting
    ``socialWebhookSecretIdentifier`` and then clear ``socialWebhookSecret``.
-   The ``socialWebhookUrl`` must point to a host on the public internet; a
    host that resolves to a non-public address is refused.

Security
--------

-   The social webhook is sent only to a checked public host, without
    redirects and with a 15-second limit.
-   The Schaubild body the text model writes is reduced to static markup
    before it is rendered.

Added
-----

-   The backend chat can start a repurpose job through the tool
    ``start_repurpose_job`` (off by default, every call needs approval), see
    :ref:`usage-chat-tool`.
-   Extension setting ``chromiumSandbox`` (default off) starts Chromium with
    its sandbox.

.. _version-0-9-2:

Version 0.9.2 (2026-10-07)
==========================

The same code as 0.9.1. This version publishes the fixes of 0.9.1 to TER,
where 0.9.1 could not be uploaded.

.. _version-0-9-1:

Version 0.9.1 (2026-10-07)
==========================

Security
--------

-   A ``url`` or ``pdf_url`` source is fetched from exactly the addresses its
    host was checked with; the PHP extension ``curl`` is now required for
    fetching. With an HTTP proxy configured, the proxy resolves the name.
-   The backend module shows a user the jobs they created. Administrators and
    users who may approve artifacts see every job.
-   A PDF with more pages than the new extension setting ``maxPdfPages``
    (default 200) is refused before its pages are read, and the analysis
    refuses a source text of more than 100 sections before the first
    completion call.

.. _version-0-9-0:

Version 0.9.0 (2026-09-30)
==========================

Security
--------

-   A source URL can no longer reach the host or the internal network: only
    ``http`` and ``https`` are fetched, and a host that resolves to a
    loopback, private, link-local or reserved address is refused.
-   A remote source is limited to 5 MiB and 30 seconds (a PDF to 50 MiB and
    120 seconds), and redirects are not followed. The time limit covers the
    whole transfer only with the PHP extension ``curl``; without it, it
    applies to each read. See :ref:`troubleshooting-source-limits`.
-   Job and artifact errors no longer show the text model's messages, server
    paths, SQL, process output or webhook URLs; those go to the TYPO3 log. A
    source URL in an error, a label or a prompt is shown without user name,
    password, query and fragment.
-   HTML renders run without JavaScript and without network access.
-   Vision OCR of a PDF needs the ``nrrepurpose:generate_vision`` permission.

Added
-----

-   A developer chapter, a troubleshooting page and a usage chapter with
    screenshots of the backend module.
-   ``LICENSE`` with the GPL-2.0 text, and README instructions for verifying
    a release.

Changed
-------

-   **Requires nr-vault 1.1** (``^1.1``, was ``^0.16``) and therefore nr-llm
    0.38.2 or later. nr-vault 1.x refuses a provider host that DNS does not
    resolve: a host reached through :file:`/etc/hosts` or a container
    runtime's resolver needs an entry in
    ``$GLOBALS['TYPO3_CONF_VARS']['HTTP']['allowed_hosts']``.
-   The Netresearch-theme Schaubild, story slides, slide deck and handout
    carry the [n] logo and a linked company footer; the extension icon is the
    [n] symbol.
-   Record forms, permission options and extension settings are translated
    (English and German).
-   ``composer.json`` carries the extension version and
    ``providesPackages``; ``typo3/cms-install`` moves to ``require-dev``.
-   The renderer passes the Chromium path to ``render.cjs`` itself; the
    worker no longer needs to export ``CHROMIUM_PATH``. See
    :ref:`configuration-rendering`.
-   **Breaking for custom PHP code:** the pipeline passes a typed
    ``JobSnapshot`` instead of the raw job row
    (``SourceIngestionServiceInterface::ingest()``,
    ``DocumentAnalyzerInterface::analyze()``, ``PdfFileResolver::resolve()``,
    ``GenerationContext``), and ``ProcessRunnerInterface::run()`` takes an
    optional ``$env`` argument. Code that implements or calls these has to
    follow.

Fixed
-----

-   A failed job names how many formats failed, instead of showing an empty
    error, and its artifact count matches its artifacts.
-   A ``pdf_fal`` job reads the PDF attached to it, not the file whose uid
    equals the number of attachments.
-   Story headlines no longer run off the slide, and the footer and copy stay
    legible over a KI background.
-   A podcast no longer fails on a numeric persona name or a nested value in
    the dialogue script.
-   Slide renders and most temp files of a run are removed.
-   The job list is paged, draws its progress bar and fits the module; error
    text on cards and table rows is readable in the dark scheme.
-   Source downloads work under Guzzle 8.
-   ``ext_emconf.php`` states the same dependency ranges as
    ``composer.json``.

.. _version-0-8-2:

Version 0.8.2 (2026-09-27)
==========================

Fixed
-----

-   The *Create & queue* button gives its feedback again: its script is now an
    ES module instead of an inline script the backend Content Security Policy
    refused, so a double click can no longer queue a job twice.
-   An alt-text generator listening to FAL events no longer fails every PNG
    artifact: files are written with their bytes in place before FAL indexes
    them. The podcast subtitles (``vtt``) are added to ``textfile_ext``.

.. _version-0-8-1:

Version 0.8.1 (2026-09-27)
==========================

Changed
-------

-   Accepts nr-llm 0.38 (``^0.35 || ^0.36 || ^0.37 || ^0.38``).

.. _version-0-8-0:

Version 0.8.0 (2026-09-27)
==========================

Added
-----

-   **Story video**: the finished story slides can also become one silent
    1080×1920 MP4 (option *Also as video*).
-   **Approval step** for every artifact, gated by the custom permission
    ``nrrepurpose:approve_artifacts``. See :ref:`adr-007`.
-   **Social planning**: approved social posts are scheduled and sent to a
    webhook by the command ``nr_repurpose:publish-due``. See
    :ref:`configuration-social`.
-   **Two documents as PDF**: a 16:9 slide deck and an A4 handout, printed by
    the headless Chromium. See :ref:`adr-006`.
-   The PDF carries the AI label in its document information and XMP
    metadata.

New database columns: run the database analyzer after the update.

.. _version-0-7-0:

Version 0.7.0 (2026-09-26)
==========================

Added
-----

-   **Every artifact is labelled as AI-generated**, machine-readable in each
    stored file and in the artifact metadata; the visible labels are
    controlled by ``aiLabelImages`` and ``aiLabelTexts``. See
    :ref:`configuration-ai-label` and :ref:`adr-005`.

.. _version-0-6-0:

Version 0.6.0 (2026-09-25)
==========================

Security
--------

-   Source text can no longer pose as an instruction in the text completions
    (document analysis, podcast, Schaubild, story, text formats): the source
    material reaches the model only as one block marked as untrusted data
    (CWE-1427). Image-generation prompts and the PDF vision OCR are not
    covered; see :ref:`adr-004`.

Added
-----

-   **Four text formats**: executive summary, FAQ, social posts and
    newsletter text, each one schema-validated nr-llm call, off by default.
    See :ref:`adr-004`.

New database columns: run the database analyzer after the update.

.. _version-0-5-3:

Version 0.5.3 (2026-09-24)
==========================

Changed
-------

-   Accepts nr-llm 0.37 (``^0.35 || ^0.36 || ^0.37``).

.. _version-0-5-2:

Version 0.5.2 (2026-09-23)
==========================

Changed
-------

-   Accepts nr-llm 0.36 (``^0.35 || ^0.36``).

Fixed
-----

-   The permissions ``generate_audio`` and ``generate_vision`` are enforced.
    Editors whose groups do not carry them no longer get podcast audio and AI
    imagery. See :ref:`configuration-permissions` and :ref:`adr-008`.

.. _version-0-5-1:

Version 0.5.1 (2026-09-17)
==========================

Fixed
-----

-   :path:`ext_emconf.php` declares ``nr_llm 0.35.0-0.35.99``, matching
    :path:`composer.json`.

.. _version-0-5-0:

Version 0.5.0 (2026-09-17)
==========================

Changed
-------

-   Requires nr-llm ``^0.35``.

Fixed
-----

-   The ``nr_repurpose_text`` preset is declared once; the duplicate made
    nr-llm's Configurations module answer with an error.
-   Resolving the ``nr_repurpose_text`` configuration passes the value object
    nr-llm 0.35 expects; a string raised a ``TypeError`` that would have
    bypassed the fallback to the instance-default configuration.

.. _version-0-4-9:

Version 0.4.9 (2026-09-03)
==========================

Fixed
-----

-   The ``nr_repurpose_text`` preset asks only for the ``chat`` capability,
    so it can be imported.
-   :path:`ext_emconf.php` declares ``nr_vault``.

.. _version-0-4-8:

Version 0.4.8 (2026-09-03)
==========================

Added
-----

-   The **Content Repurpose Starter** use-case pack. See
    :ref:`configuration-starter-pack`.

Changed
-------

-   Requires nr-llm ``^0.34``.

.. _version-0-4-7:

Version 0.4.7 (2026-08-21)
==========================

Changed
-------

-   Requires nr-llm ``^0.33``.

.. _version-0-4-6:

Version 0.4.6 (2026-08-21)
==========================

Added
-----

-   Every nr-llm call names this extension and its pipeline step, so nr-llm's
    analytics attribute usage and cost to ``nr_repurpose``.

Changed
-------

-   Requires nr-llm ``^0.32``.

.. _version-0-4-5:

Version 0.4.5 (2026-08-20)
==========================

Changed
-------

-   Requires nr-llm ``^0.31``.

.. _version-0-4-4:

Version 0.4.4 (2026-08-19)
==========================

Changed
-------

-   Requires nr-llm ``^0.30``.

.. _version-0-4-3:

Version 0.4.3 (2026-08-13)
==========================

Changed
-------

-   Accepts nr-llm 0.29 alongside 0.28.

.. _version-0-4-2:

Version 0.4.2 (2026-08-11)
==========================

Added
-----

-   The extension setting :confval:`technicalBeUserUid <technicalbeuseruid>`.

Fixed
-----

-   Generation jobs no longer fail in the analysis step with a denied
    nr-vault read, once a technical backend user is configured.

Changed
-------

-   Requires nr-vault ``^0.15``.

.. _version-0-4-1:

Version 0.4.1 (2026-08-10)
==========================

Changed
-------

-   Requires nr-llm ``^0.28``.

.. _version-0-4-0:

Version 0.4.0 (2026-08-07)
==========================

Changed
-------

-   Requires nr-llm ``^0.26``.

Removed
-------

-   The public alias for nr-llm's capability permission service, which
    nr-llm 0.26 removed.

.. _version-0-3-1:

Version 0.3.1 (2026-07-31)
==========================

Changed
-------

-   Accepts nr-vault 0.12 (``^0.10.0 || ^0.11.0 || ^0.12.0``).

Fixed
-----

-   :path:`Documentation/guides.xml` states the released version.

.. _version-0-3-0:

Version 0.3.0 (2026-07-24)
==========================

Changed
-------

-   Requires nr-llm ``^0.25``.

.. _version-0-2-3:

Version 0.2.3 (2026-07-22)
==========================

Changed
-------

-   Accepts Symfony 8 for ``symfony/process`` and ``symfony/messenger``.

Fixed
-----

-   Composer resolves the latest tag: the explicit ``version`` field is removed
    from :path:`composer.json`.

.. _version-0-2-2:

Version 0.2.2 (2026-07-22)
==========================

Added
-----

-   nr-llm 0.23 support (``completeStructured()``), and a documentation render
    job in CI.

Fixed
-----

-   The documentation renders again (repaired :path:`guides.xml`).

.. _version-0-2-1:

Version 0.2.1 (2026-07-21)
==========================

Changed
-------

-   Backend icons in the TYPO3 v14 style, and a record icon for artifacts.
-   Accepts nr-vault 0.11.

Fixed
-----

-   Borders in the job detail view follow the dark scheme.

.. _version-0-2-0:

Version 0.2.0 (2026-07-18)
==========================

Added
-----

-   **One-click nr-llm configuration presets** for ``nr_repurpose_text``,
    ``nr_repurpose_image`` and ``nr_repurpose_tts``.
-   Text generation routes through the ``nr_repurpose_text`` configuration.

Changed
-------

-   Requires nr-llm ``^0.22.0``.

.. _version-0-1-0:

Version 0.1.0 (2026-06-12)
==========================

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
