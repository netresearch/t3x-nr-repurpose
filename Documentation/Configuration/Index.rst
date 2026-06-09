.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

nr_repurpose has a small amount of its own configuration
(:ref:`configuration-ext-conf`), but most of what it needs is wiring it shares
with the host instance: the nr-llm provider, the Symfony Messenger routing, and
the outbound HTTP timeouts. In the bundled DDEV environment all of the
instance-level settings below are written to
:path:`config/system/additional.php` by ``ddev install``; in a real deployment
you place them in your instance configuration.

.. _configuration-nr-llm:

nr-llm provider wiring
=====================

nr_repurpose calls OpenAI exclusively through nr-llm, so nr-llm must have an
OpenAI provider configured that resolves its key from nr-vault by identifier.
The key was stored under ``nr_repurpose_openai`` during installation (see
:ref:`installation-openai-key`).

.. code-block:: php
   :caption: config/system/additional.php — nr-llm OpenAI provider

   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['providers']['openai']['apiKeyIdentifier'] = 'nr_repurpose_openai';
   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['defaultProvider'] = 'openai';
   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['providers']['openai']['defaultModel'] = 'gpt-4o';

Since nr-llm ``0.10.0`` there is a single key-resolution path: both the
chat/vision providers and the specialized services (TTS, images) authenticate
through nr-vault's secure HTTP client by identifier — no plaintext
``providers.openai.apiKey`` is set. See :ref:`adr-003`.

.. _configuration-messenger:

Messenger routing
================

Job submission dispatches a
:php:`Netresearch\\NrRepurpose\\Queue\\Message\\GenerateArtifactsMessage`. Route
it to an asynchronous transport (the doctrine transport in the dev setup) so the
HTTP request that created the job returns immediately and the
:ref:`worker <installation-worker>` does the long-running work:

.. code-block:: php
   :caption: config/system/additional.php — route the generation message async

   use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;

   $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'][GenerateArtifactsMessage::class] = 'doctrine';
   $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing']['*'] = 'default';

.. note::

   TYPO3 v14.3 Core has no retry/failure transport. The message handler
   therefore catches a hard failure, marks the job failed, and does **not**
   rethrow — otherwise the message would be lost with no record. See
   :ref:`adr-001`.

.. _configuration-http:

HTTP timeouts
============

The generator worker makes outbound calls to OpenAI (script, TTS, image) and
fetches source URLs. TYPO3's shared Guzzle client defaults to ``timeout = 0``
(no read timeout), so a stalled provider response would hang the worker. Bound
it:

.. code-block:: php
   :caption: config/system/additional.php — bound outbound HTTP

   $GLOBALS['TYPO3_CONF_VARS']['HTTP']['timeout'] = 180;
   $GLOBALS['TYPO3_CONF_VARS']['HTTP']['connect_timeout'] = 15;

.. _configuration-rendering:

Renderer environment
===================

The HTML-to-PNG renderer shells out to ``node`` running the bundled
``render.cjs``, which launches the system Chromium. The PHP renderer exports
``CHROMIUM_PATH`` into the process environment for the script; the default is
``/usr/bin/chromium``. ``ffmpeg`` and ``ffprobe`` are expected on ``PATH``.
These defaults are baked into the service definitions and need no scalars in a
standard install; override them only if your binaries live elsewhere.

.. _configuration-ext-conf:

Extension configuration
======================

The extension's own options live in :path:`ext_conf_template.txt` and are read
through the typed :php:`Netresearch\\NrRepurpose\\Configuration\\RepurposeConfiguration`
accessor. Set them under
:guilabel:`Admin Tools > Settings > Extension Configuration > nr_repurpose`.

.. list-table::
   :header-rows: 1
   :widths: 28 18 54

   * - Key
     - Default
     - Purpose
   * - ``voices.hostA``
     - ``nova``
     - Podcast Host A voice (OpenAI TTS voice name).
   * - ``voices.hostB``
     - ``onyx``
     - Podcast Host B voice.
   * - ``tts.model``
     - ``tts-1-hd``
     - Text-to-speech model (``tts-1`` or ``tts-1-hd``).
   * - ``image.provider``
     - ``dalle``
     - Image generation service (``dalle`` = OpenAI images, or ``fal``).
   * - ``image.model``
     - ``gpt-image-1``
     - Image model. DALL·E-3 was retired by OpenAI; ``gpt-image-1`` is the
       default.
   * - ``diagram.viewportWidth``
     - ``1200``
     - Schaubild render viewport width, in pixels.
   * - ``story.width``
     - ``1080``
     - Instagram story width, in pixels.
   * - ``story.height``
     - ``1920``
     - Instagram story height, in pixels.
   * - ``defaultTheme``
     - ``nr``
     - Default theme for new jobs: ``nr`` (Netresearch branded) or ``neutral``.
   * - ``mapReduce.charThreshold``
     - ``12000``
     - Source-text character count above which the analyzer switches to chunked
       map-reduce analysis.

.. _configuration-permissions:

Backend capability permissions
=============================

nr_repurpose registers two ``customPermOptions`` under the ``nrrepurpose``
namespace so backend group editors can gate AI spend per group:

-   ``generate_audio`` — podcast audio generation (maps to the nr-llm
    ``AUDIO`` capability).
-   ``generate_vision`` — AI imagery generation (maps to the nr-llm ``VISION``
    capability).

nr-llm has no dedicated image/speech capability, so audio generation gates on
``AUDIO`` and image/vision generation on ``VISION``.
