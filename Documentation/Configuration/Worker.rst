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

.. note::

   The transport hands a message out again while its first delivery still
   runs, once 3600 seconds have passed, and after a worker died. One run owns
   a job at a time: a second delivery while the first run is alive changes
   nothing and is sent again after 30 minutes; the delivery after a dead
   worker takes the job over once the dead run has written nothing for 30
   minutes. A provider call must not outlast that: for every nr-llm provider
   record, ``(max_retries + 1) × api_timeout`` must stay at or below 1380
   seconds (nr-llm's defaults, 3 retries of 120 seconds, give 480).

   The claim lives in the job columns ``run_token`` and ``run_claimed_at``.
   When installing a version that adds them, stop every worker first, update
   the database schema, and start the workers again only afterwards: a new
   worker fails on a database without the columns, and an old worker beside a
   new one runs jobs without a claim. The claim's age is measured with each
   host's own clock, so keep the clocks of all worker and web hosts
   synchronised (NTP). A job whose worker died and whose message is gone can
   be started again from its result view once it has stood still for two
   hours. See :ref:`adr-001`.

.. _configuration-http:

HTTP timeouts
============

The generator worker makes outbound calls to the configured AI providers
(script, TTS, image). TYPO3's shared Guzzle client defaults to
``timeout = 0`` (no read timeout), so a stalled provider response would hang
the worker. Bound it:

.. code-block:: php
   :caption: config/system/additional.php — bound outbound HTTP

   $GLOBALS['TYPO3_CONF_VARS']['HTTP']['timeout'] = 300;
   $GLOBALS['TYPO3_CONF_VARS']['HTTP']['connect_timeout'] = 15;

Since nr-llm ``0.12.0`` the specialized image/TTS calls carry their own
per-request timeout (image default 300 s), so a long-running image generation
is not cut off by a shorter global value; the global timeout still governs the
chat calls. Source-URL fetches and the social webhook set their own limits per
request (30 seconds for a web page, 120 for a PDF, 15 for the webhook) and do
not depend on this value (see :ref:`troubleshooting-source-limits`).

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

.. _configuration-chromium-sandbox:

Chromium sandbox
----------------

The rendered HTML carries text the language model wrote from the source.
``render.cjs`` disables JavaScript and blocks every network request, and the
diagram body is reduced to static markup before it is rendered. With the
extension setting ``chromiumSandbox`` on, Chromium also runs with its sandbox,
so a fault in its HTML or CSS handling stays confined to a process without
access to the worker's files and credentials.

The sandbox needs unprivileged user namespaces or Chromium's setuid sandbox
helper on the host that runs the worker. A container started with Docker's
default seccomp profile does not provide them, and Chromium refuses to start
as root with its sandbox; every render then fails with ``HTML render failed``
and the cause (``No usable sandbox!`` or ``Running as root without
--no-sandbox is not supported``) is in the TYPO3 log. The setting is therefore
off by default. Turn it on where the worker runs as an unprivileged user on a
host or in a container that allows user namespaces, and check one Schaubild
after the change.
