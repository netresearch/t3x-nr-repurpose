.. include:: /Includes.rst.txt

.. _troubleshooting:

===============
Troubleshooting
===============

A job that fails as a whole shows its error in the job's detail view. An
artifact that fails on its own shows its error on the artifact card; the other
artifacts of the run are still produced. The worker also writes each failure to
the TYPO3 log.

.. _troubleshooting-queued:

The job stays "queued"
======================

Submitting a job only dispatches a message; a worker does the generation. A job
that never leaves ``queued`` means no worker consumes the transport the message
is routed to.

-   Check that the generation message is routed to the ``doctrine`` transport
    (see :ref:`configuration-messenger`).
-   Run a consumer for that transport, and keep it running (see
    :ref:`installation-worker`):

    .. code-block:: bash
       :caption: Consume the generation transport

       vendor/bin/typo3 messenger:consume doctrine

-   To run one job without the worker, for example while debugging, use
    ``vendor/bin/typo3 nr_repurpose:generate <jobUid>``.

.. _troubleshooting-vault:

The job fails in "analyzing" with a denied secret
=================================================

The error reads like ``Access denied to secret "…": insufficient
permissions``, and no artifact is produced. The worker and the CLI command run
as an unauthenticated command-line user, and nr-vault refuses every secret
read without an actor.

Set :confval:`technicalBeUserUid <technicalbeuseruid>` to a backend user that
may read the provider key's secret: a user with access to the secret through
its owner or its groups, or an administrator as long as nr-vault's
administrator override is not disabled.

.. _troubleshooting-renderer:

Schaubild or story images fail with "HTML render failed"
========================================================

The artifact error contains ``HTML render failed (exit …)`` followed by the
renderer's error output. The images are rendered by ``node`` running the
bundled ``render.cjs``, which starts Chromium.

-   ``node`` must be on the worker's ``PATH``, and the worker's environment
    must export ``CHROMIUM_PATH`` with the path of the Chromium binary. The
    error output ``Executable doesn't exist at …`` with a path in Playwright's
    own browser directory means ``CHROMIUM_PATH`` did not reach the renderer
    (see :ref:`configuration-rendering`).
-   The renderer's own dependency must be installed (see
    :ref:`installation-node-renderer`).

The slide deck and the handout are printed by the same renderer and fail the
same way.

.. _troubleshooting-ffmpeg:

The podcast or the story video fails with an ffmpeg error
=========================================================

The artifact error contains ``ffmpeg concat failed (exit …)`` or
``ffprobe failed (exit …)`` for the podcast, or
``ffmpeg slideshow failed (exit …)`` for the story video. ``ffmpeg`` and
``ffprobe`` must be on the worker's ``PATH``.

.. _troubleshooting-source-url:

A URL job fails with "Source URL …"
===================================

The worker fetches a *Webpage URL* or *PDF URL* source only over ``http`` or
``https`` and only from a host outside the local and internal network. It
refuses any other URL before the request, and the job fails with one of these
errors:

-   ``Source URL scheme "…" is not allowed, only http and https: …``
-   ``Source URL has no host: …``
-   ``Source URL host … is a numeric IPv4 spelling; write the address as four decimal numbers (a.b.c.d)``
-   ``Source URL host does not resolve: …``
-   ``Source URL host … resolves to a loopback, private, link-local or reserved address``
    — the host, or one of the addresses it resolves to, lies in the
    local or internal network. The address itself is written to the TYPO3
    log, not to the error.
-   ``Source URL cannot be parsed: …`` — the HTTP library cannot read the URL;
    its message is in the TYPO3 log.

No setting allows an internal host. For a PDF that only an internal server
provides, attach the file to the job as a *PDF file (FAL)* source.

The worker does not follow redirects, because a redirect target would bypass
this check. A redirecting URL fails with ``URL returned HTTP 301: …`` or
``PDF URL returned HTTP 302: …`` (or another 3xx status); enter the URL the
redirect points to.

.. _troubleshooting-source-limits:

A URL job fails with "Source is larger than …" or "Source did not arrive within …"
==================================================================================

The worker reads a web page up to 5 MiB within 30 seconds and downloads a PDF
up to 50 MiB within 120 seconds. The limits are fixed.

-   ``Source is larger than 5 MiB: …`` (``50 MiB`` for a PDF) — the source is
    larger than the limit. Attach a larger PDF to the job as a *PDF file (FAL)*
    source instead.
-   ``Source did not arrive within 30 seconds: …`` (``120 seconds`` for a
    PDF) — the server did not deliver the source in time.
-   ``Reading the source failed: …`` — the transfer broke off while the body
    was read.

Without the PHP extension ``curl`` the time limit applies to each read only,
not to the whole transfer, so a server that sends its response headers very
slowly can still hold the worker. Install ``curl`` on the host that runs the
worker (see :ref:`installation-requirements`).

.. _troubleshooting-poppler:

A PDF job fails with "pdftoppm failed" or "pdftotext -layout failed"
====================================================================

The PDF modes ``vision`` and ``tables``, and ``auto`` for sparse or tabular
pages, call the poppler tools ``pdftoppm`` and ``pdftotext``. Install
``poppler-utils`` on the host that runs the worker.

``No text could be extracted from the PDF`` means that no tier returned any
text; ``PDF could not be parsed (possibly encrypted or damaged)`` means the file
could not be opened.

.. _troubleshooting-not-permitted:

An artifact fails with "Not permitted"
======================================

The error names the missing option, ``nrrepurpose:generate_audio`` or
``nrrepurpose:generate_vision``. The backend groups of the user who created the
job do not grant it. Grant it in the group's custom module options (see
:ref:`configuration-permissions`).

Reading a PDF with Vision OCR needs ``nrrepurpose:generate_vision`` as well.
Without it a page keeps its embedded text and the job continues. A PDF that has
no embedded text at all fails the whole job with this error:

``The PDF has no embedded text, and reading it with Vision OCR is not permitted: the job owner's backend groups do not grant "Generate AI imagery" (nrrepurpose:generate_vision)``

.. _troubleshooting-budget:

An artifact fails with "AI budget exhausted or … unavailable"
=============================================================

The error reads ``AI budget exhausted or speech synthesis unavailable`` or
``AI budget exhausted or image service unavailable``. Either the job creator's
nr-llm budget does not cover the planned cost, or nr-llm's speech or image
service is not available, for example because the ``nr_repurpose_tts`` or
``nr_repurpose_image`` configuration or its provider is missing (see
:ref:`configuration-nr-llm`). The Schaubild's plain HTML variant needs neither
and is still produced.

.. _troubleshooting-memory:

The worker dies while compositing images
========================================

PHP's ``memory_limit`` must be higher than the ``--memory-limit`` passed to
``messenger:consume``, with room for GD image compositing. Otherwise PHP stops
the process before Messenger can restart it, and the message is delivered again
into the same crash. See :ref:`installation-worker`.
