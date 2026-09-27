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
may read the provider key's secret: an administrator, or a user with access to
the secret through its owner or its groups.

.. _troubleshooting-renderer:

Schaubild or story images fail with "HTML render failed"
========================================================

The artifact error contains ``HTML render failed (exit …)`` followed by the
renderer's error output. The images are rendered by ``node`` running the
bundled ``render.cjs``, which starts Chromium.

-   ``node`` must be on the worker's ``PATH`` and Chromium installed at
    ``/usr/bin/chromium`` (see :ref:`configuration-rendering`).
-   The renderer's own dependency must be installed (see
    :ref:`installation-node-renderer`).

The slide deck and the handout are printed by the same renderer and fail the
same way.

.. _troubleshooting-ffmpeg:

The podcast or the story video fails with an ffmpeg error
=========================================================

The artifact error contains ``ffmpeg concat failed (exit …)`` or
``ffprobe failed (exit …)`` for the podcast, or
``ffmpeg slideshow failed (exit …)`` for the story video. ``ffmpeg`` and ``ffprobe`` must be on the worker's ``PATH``.

.. _troubleshooting-poppler:

A PDF job fails with "pdftoppm failed" or "pdftotext -layout failed"
====================================================================

The PDF modes ``vision`` and ``tables``, and ``auto`` for sparse or tabular
pages, call the poppler tools ``pdftoppm`` and ``pdftotext``. Install
``poppler-utils`` on the host that runs the worker.

``No text could be extracted from the PDF`` means that no tier returned any
text; ``PDF could not be parsed (possibly encrypted)`` means the file could not
be opened.

.. _troubleshooting-not-permitted:

An artifact fails with "Not permitted"
======================================

The error names the missing option, ``nrrepurpose:generate_audio`` or
``nrrepurpose:generate_vision``. The backend groups of the user who created the
job do not grant it. Grant it in the group's custom module options (see
:ref:`configuration-permissions`).

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
