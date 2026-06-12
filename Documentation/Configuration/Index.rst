.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

nr_repurpose has no configuration of its own — everything it needs is wiring it
shares with the host instance: the nr-llm provider, model and Configuration
records, the Symfony Messenger routing, and the outbound HTTP timeouts. In the
bundled DDEV environment the instance-level settings below are written to
:path:`config/system/additional.php` by ``ddev install``; in a real deployment
you place them in your instance configuration.

.. _configuration-nr-llm:

nr-llm wiring: providers, models, Configurations
================================================

nr_repurpose never talks to an AI provider directly and never picks one itself.
It names nr-llm **Configuration** records (use cases) and lets nr-llm resolve
the model, the provider, the API key, the system prompt, and the usage/cost
attribution. Set everything up in nr-llm's backend module
(:guilabel:`Admin Tools > LLM Management`):

#.  Create a **Provider** whose API key references the nr-vault identifier you
    stored during installation (see :ref:`installation-openai-key`).
#.  Create the **Models** you want to use (or fetch them via nr-llm's model
    discovery), including the specialized ones (image, text-to-speech).
#.  Create the **Configuration** records nr_repurpose consumes:

.. list-table::
   :header-rows: 1
   :widths: 26 30 44

   * - Configuration identifier
     - Used for
     - Model choice
   * - *(the default Configuration)*
     - Analysis and copy: the brief, the podcast script, the diagram body, the
       story copy.
     - Any chat model of any nr-llm provider — OpenAI, Anthropic Claude,
       Google Gemini, Groq, Mistral, Ollama, OpenRouter.
   * - ``nr_repurpose_image``
     - AI imagery (Schaubild backgrounds and full images, story backgrounds).
     - Any model accepted by nr-llm's image services; falls back to
       ``gpt-image-2`` when the record is absent. The record's system prompt
       acts as a style preamble for every image prompt.
   * - ``nr_repurpose_tts``
     - Podcast speech synthesis.
     - Any model of nr-llm's text-to-speech service (currently OpenAI ``tts-1``
       / ``tts-1-hd``); falls back to ``tts-1``.

Swapping a model — or, for text, the provider — is a backend-only change: edit
the Configuration record, no code or deployment involved. Per-model and
per-configuration usage and cost appear in nr-llm's analytics module.

Image and speech calls go through nr-llm's *specialized* services, which
currently cover OpenAI (images, TTS) and fal.ai (images). The extension-side
seam for additional backends is the
:php:`ImageGeneratorInterface` / :php:`SpeechSynthesizerInterface` DI alias in
:path:`Configuration/Services.yaml`.

Key resolution is vault-only: both the chat providers and the specialized
services authenticate through nr-vault's secure HTTP client by identifier — no
plaintext key is ever set. See :ref:`adr-003`.

.. _configuration-snippets:

Prompt snippets
===============

The *New job* form's *audience*, *tone of voice*, *persona*, *layout* and
*style* selectors are populated from nr-llm's prompt-snippet library (snippets
tagged ``audience``, ``tone_of_voice``, ``persona``, ``layout``, ``style``).
Editors maintain them in nr-llm's backend module; each snippet's description is
shown in the form so the choice is informed. A ``layout`` snippet may carry an
``imageSize`` metadata key (``"WIDTHxHEIGHT"``) that sets the AI-image
dimensions for that channel — e.g. skyscraper ``768x2160``, wide ``2160x768``.

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

The generator worker makes outbound calls to the configured AI providers
(script, TTS, image) and fetches source URLs. TYPO3's shared Guzzle client
defaults to ``timeout = 0`` (no read timeout), so a stalled provider response
would hang the worker. Bound it:

.. code-block:: php
   :caption: config/system/additional.php — bound outbound HTTP

   $GLOBALS['TYPO3_CONF_VARS']['HTTP']['timeout'] = 300;
   $GLOBALS['TYPO3_CONF_VARS']['HTTP']['connect_timeout'] = 15;

Since nr-llm ``0.12.0`` the specialized image/TTS calls carry their own
per-request timeout (image default 300 s), so a long-running image generation
is not cut off by a shorter global value; the global timeout still governs the
chat calls and source-URL fetches.

.. _configuration-rendering:

Renderer environment
===================

The HTML-to-PNG renderer shells out to ``node`` running the bundled
``render.cjs``, which launches the system Chromium. The PHP renderer exports
``CHROMIUM_PATH`` into the process environment for the script; the default is
``/usr/bin/chromium``. ``ffmpeg`` and ``ffprobe`` are expected on ``PATH``.
These defaults are baked into the service definitions and need no scalars in a
standard install; override them only if your binaries live elsewhere.

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
