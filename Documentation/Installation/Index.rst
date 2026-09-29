.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _installation:

============
Installation
============

.. _installation-requirements:

Requirements
============

.. list-table::
   :header-rows: 1
   :widths: 30 70

   * - Requirement
     - Notes
   * - PHP
     - ``^8.3``
   * - TYPO3
     - ``^14.3`` (v14.3 LTS only)
   * - :composer:`netresearch/nr-llm`
     - ``^0.35 || ^0.36 || ^0.37 || ^0.38`` — AI access (completion, TTS,
       image), budget enforcement and one-click configuration presets.
   * - :composer:`netresearch/nr-vault`
     - ``^1.1`` — holds the provider keys nr-llm reads; its
       technical-actor API lets the worker read them (see
       :ref:`configuration-extension-settings`).
   * - PHP extension ``curl`` (recommended)
     - Fetching ``url`` and ``pdf_url`` sources. With it the time limit covers
       the whole transfer including the response headers; without it a server
       that sends its headers very slowly can hold the worker.
   * - ``poppler-utils``
     - ``pdftoppm`` / ``pdftotext`` for PDF ingestion (Vision OCR and layout
       tiers).
   * - ``ffmpeg`` / ``ffprobe``
     - Concatenate the podcast MP3 segments and measure segment durations for
       the WebVTT cue timing.
   * - ``chromium``
     - Headless browser the Node renderer drives to turn HTML into PNGs.
   * - Node.js
     - ``>=22.18.0 <25.0.0`` to run the bundled ``render.cjs`` (uses
       ``playwright-core``).

The version ranges are the ones :path:`composer.json` requires; that file is
authoritative.

.. note::

   The system binaries (``poppler-utils``, ``ffmpeg``, ``chromium``) are not
   PHP dependencies — they must be present on the host (and on the worker host).
   In the bundled DDEV environment they are baked into the web image.

.. _installation-composer:

Composer installation
=====================

.. code-block:: bash
   :caption: Install via Composer

   composer require netresearch/nr-repurpose

This pulls in nr-llm and its own dependencies. After installation, set up the
extension's database tables and activate it:

.. code-block:: bash
   :caption: Set up the extension

   vendor/bin/typo3 extension:setup nr_repurpose
   vendor/bin/typo3 cache:flush

The extension creates two tables:

.. list-table::
   :header-rows: 1
   :widths: 45 55

   * - Table
     - Purpose
   * - :sql:`tx_nrrepurpose_domain_model_job`
     - One row per generation run (source, selected artifacts, theme, status,
       progress).
   * - :sql:`tx_nrrepurpose_domain_model_artifact`
     - One row per produced artifact (type, variant, FAL file references,
       transcript, metadata, status).

.. _installation-classic:

Classic mode (TER) is not supported
===================================

.. warning::

   nr_repurpose requires a Composer-based TYPO3 installation. Installing it
   through the Extension Manager (classic mode) is not supported.

The extension needs code that only a Composer installation provides:

-   **A PHP library.** PDF ingestion uses :composer:`smalot/pdfparser`, which
    :path:`composer.json` requires. The TER package contains no
    :path:`vendor/` directory, so the library is missing in classic mode.
-   **The Node renderer's dependencies.** The TER package ships only
    :path:`Resources/Private/NodeRenderer/render.cjs`, without the
    :path:`package.json` and :path:`package-lock.json` that
    :ref:`installation-node-renderer` installs ``playwright-core`` from. Without
    them the Schaubild, story, slide deck and handout cannot be rendered.

The extension is listed in the TYPO3 Extension Repository as
`nr_repurpose <https://extensions.typo3.org/extension/nr_repurpose>`__ so it
can be found there. Install it with Composer as described in
:ref:`installation-composer`.

.. _installation-node-renderer:

Install the Node renderer
========================

The image renderer is a small Node script under
:path:`Resources/Private/NodeRenderer/`. Install its single dependency
(``playwright-core``) and rely on the system ``chromium`` instead of letting
Playwright download its own browser:

.. code-block:: bash
   :caption: Install the renderer (skip the Playwright browser download)

   cd Resources/Private/NodeRenderer
   PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm ci

The renderer starts the Chromium binary at ``/usr/bin/chromium``. For another
path, see :ref:`configuration-rendering`.

.. _installation-openai-key:

Hand the provider key to nr-llm
===============================

nr_repurpose never reads an API key — it owns no provider credentials at all.
Give the key (the examples use OpenAI, the tested default) to nr-llm, which
stores it and hands back the identifier its records reference.

The normal route is nr-llm's setup wizard in the TYPO3 backend: enter the key,
and nr-llm stores it securely and generates the key identifier for you. How it
keeps the secret is nr-llm's business and documented there.

Two places then refer to that identifier, both of them nr-llm's: the **Provider**
record carries it for the chat and vision completions, and nr-llm's extension
configuration carries it as ``providers.openai.apiKeyIdentifier`` for the
specialized text-to-speech and image services. The Configuration records
nr_repurpose ships as presets bake in no provider, model or key at all. See
:ref:`configuration-nr-llm`.

.. note::

   For scripted installs where no one can operate the wizard, nr-llm's storage
   backend can also be filled from the command line — that is what the bundled
   DDEV setup does, seeding the identifier ``nr_repurpose_openai``. See
   :ref:`installation-ddev`.

.. _installation-worker:

Run the generation worker
========================

Generation runs asynchronously: job submission only dispatches a message, and a
Symfony Messenger worker does the actual work. Run a long-lived consumer on a
host that has the system binaries and the Node renderer installed:

.. code-block:: bash
   :caption: Consume the generation transport

   php -d memory_limit=1G vendor/bin/typo3 messenger:consume doctrine --time-limit=3600 --memory-limit=512M

Restart the consumer in a loop (systemd, a container restart policy, or a
supervisor) so it survives the deliberate time/memory limits that recycle the
process. The transport routing is configured in :ref:`configuration-messenger`.

.. important::

   The PHP ``memory_limit`` must exceed Messenger's ``--memory-limit`` soft
   restart threshold, with headroom for GD image compositing — otherwise PHP
   fatals before Messenger can recycle the process, and the unacknowledged
   message is redelivered into the same crash (re-running paid AI calls). The
   compositor pre-flights its memory need and fails the single artifact
   gracefully, but only within the limit PHP actually has.

.. note::

   The worker host runs ``chromium`` and ``ffmpeg`` and reaches the configured
   AI providers — bound the outbound HTTP timeout (see :ref:`configuration-http`)
   so a stalled provider response cannot hang the worker indefinitely.

.. _installation-ddev:

Local development with DDEV
==========================

The repository ships a DDEV setup whose web image already contains
``poppler-utils``, ``ffmpeg`` and ``chromium``, and a sidecar worker container:

.. code-block:: bash
   :caption: Bring up the DDEV environment

   cp .ddev/.env.dist .ddev/.env     # then set OPENAI_API_KEY=sk-…
   ddev start                        # builds the web image
   ddev setup                        # composer install + TYPO3 setup into .Build/Web

``ddev setup`` installs TYPO3 v14.3 into :path:`.Build/Web`, seeds the OpenAI
key into nr-vault under ``nr_repurpose_openai``, wires the nr-llm provider and
the messenger routing in :path:`config/system/additional.php`, and installs the
Node renderer. The backend is then at ``https://nr-repurpose.ddev.site/typo3/``.
