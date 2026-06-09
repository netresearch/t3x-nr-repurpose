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
     - ``^0.10.0`` — AI access (completion, TTS, image) and budget enforcement.
   * - :composer:`netresearch/nr-vault`
     - ``^0.8.0`` — stores the OpenAI key; nr-llm authenticates through it.
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

This pulls in nr-llm and nr-vault automatically. After installation, set up the
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

The renderer locates Chromium via the ``CHROMIUM_PATH`` environment variable
(default ``/usr/bin/chromium``). See :ref:`configuration-rendering`.

.. _installation-openai-key:

Store the OpenAI key in nr-vault
===============================

nr_repurpose never reads a plaintext OpenAI key. Store the key in nr-vault under
the identifier nr-llm is configured to read (``nr_repurpose_openai``):

.. code-block:: bash
   :caption: Seed the OpenAI key into nr-vault

   printf '%s' "sk-…" | vendor/bin/typo3 vault:store nr_repurpose_openai --stdin

To rotate an existing secret use ``vault:rotate nr_repurpose_openai --stdin``.
nr-vault uses the TYPO3 encryption key as its master key by default
(``masterKeyProvider=typo3``), so no extra vault setup is required. The matching
nr-llm provider configuration is described in :ref:`configuration-nr-llm`.

.. _installation-worker:

Run the generation worker
========================

Generation runs asynchronously: job submission only dispatches a message, and a
Symfony Messenger worker does the actual work. Run a long-lived consumer on a
host that has the system binaries and the Node renderer installed:

.. code-block:: bash
   :caption: Consume the generation transport

   vendor/bin/typo3 messenger:consume doctrine --time-limit=3600 --memory-limit=256M

Restart the consumer in a loop (systemd, a container restart policy, or a
supervisor) so it survives the deliberate time/memory limits that recycle the
process. The transport routing is configured in :ref:`configuration-messenger`.

.. note::

   The worker host runs ``chromium`` and ``ffmpeg`` and reaches OpenAI — bound
   the outbound HTTP timeout (see :ref:`configuration-http`) so a stalled
   provider response cannot hang the worker indefinitely.

.. _installation-ddev:

Local development with DDEV
==========================

The repository ships a DDEV setup whose web image already contains
``poppler-utils``, ``ffmpeg`` and ``chromium``, and a sidecar worker container:

.. code-block:: bash
   :caption: Bring up the DDEV environment

   cp .ddev/.env.dist .ddev/.env     # then set OPENAI_API_KEY=sk-…
   ddev start                        # builds the web image
   ddev install                      # composer install + TYPO3 setup into .Build/Web

``ddev install`` installs TYPO3 v14.3 into :path:`.Build/Web`, seeds the OpenAI
key into nr-vault under ``nr_repurpose_openai``, wires the nr-llm provider and
the messenger routing in :path:`config/system/additional.php`, and installs the
Node renderer. The backend is then at ``https://nr-repurpose.ddev.site/typo3/``.
