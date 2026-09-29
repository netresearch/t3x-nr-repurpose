.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-worker:

==================
Worker environment
==================

The worker runs the generation outside the web request. It needs the
message routed to an asynchronous transport, bounded outbound HTTP calls,
and the rendering binaries.

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
``render.cjs``, which launches Chromium. ``node``, ``ffmpeg`` and ``ffprobe``
are expected on ``PATH``; these defaults are baked into the service
definitions and need no scalars in a standard install.

The renderer passes the Chromium path to ``render.cjs`` itself, in the child
process's ``CHROMIUM_PATH`` variable. The path is the ``$chromiumPath``
argument of
``Netresearch\NrRepurpose\Rendering\PlaywrightHtmlToImageRenderer``, default
``/usr/bin/chromium``. The worker does not need to export ``CHROMIUM_PATH``.
For a Chromium binary at another path, set the argument in your site's
:path:`Services.yaml`:

.. code-block:: yaml
   :caption: config/system/services.yaml (or a site package's Services.yaml)

   Netresearch\NrRepurpose\Rendering\PlaywrightHtmlToImageRenderer:
     arguments:
       $chromiumPath: '/usr/lib/chromium/chromium'
