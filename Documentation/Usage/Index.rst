.. include:: /Includes.rst.txt

.. _usage:

=====
Usage
=====

There are two ways to run a generation: the *Repurpose* backend module
(asynchronous, the normal path) and the ``nr_repurpose:generate`` CLI command
(synchronous, for ops and debugging).

.. _usage-backend-module:

The Repurpose backend module
================================

The module registers under :guilabel:`Web > Repurpose`
(``web_nrrepurpose``) and is available to any backend user (``access: user``).
It has three views, backed by the :php:`JobController` actions ``list``,
``new`` / ``create``, and ``show``.

.. _usage-list:

Job list
--------

The landing view lists all jobs regardless of their storage page. Each row shows
the source, the selected artifacts, and the live status as the worker advances
it: ``queued → ingesting → analyzing → generating → done`` (or
``partially_done`` / ``failed``). From here you open the *New job* form or a
job's result view.

.. _usage-new:

Create a job
------------

The *New job* form submits to the ``create`` action, which persists the job and
dispatches the generation message. The form fields map directly to the job
record:

.. list-table::
   :header-rows: 1
   :widths: 24 30 46

   * - Field
     - Options
     - Notes
   * - Source type
     - Webpage URL / PDF URL / PDF file (FAL)
     - Selects how the source is ingested.
   * - Source URL
     - free text
     - The URL for *Webpage URL* and *PDF URL* sources.
   * - PDF extraction mode
     - Auto / Embedded text only / Vision OCR / Layout / tables
     - Only relevant for PDF sources. *Auto* decides per page (see
       :ref:`architecture-ingestion`).
   * - Theme
     - Netresearch CI / Neutral
     - The branded or neutral look of the rendered diagram and story.
   * - Audience / Tone of voice / Persona / Layout / Style
     - selects, populated from nr-llm prompt snippets
     - Optional prompt steering; each option shows the snippet's description.
       *Persona* shapes the podcast hosts, *layout* and *style* shape the AI
       imagery — a layout's ``imageSize`` metadata sets the image dimensions
       (see :ref:`configuration-snippets`).
   * - Podcast / Schaubild / Story
     - checkboxes (all on by default)
     - Which artifacts to generate this run.

.. note::

   For a *PDF file (FAL)* source, upload the PDF as a ``sys_file`` and attach it
   to the job record via the record edit view; the *New job* form sets the
   source type, URL and extraction mode.

After submitting, a flash message confirms the job was created and queued, and
you are redirected to the list. The worker picks the job up and processes it
asynchronously.

.. _usage-show:

Result view
-----------

While a job is still running, the view shows fine-grained per-step progress
(which generator is working and what it is doing) and refreshes itself
automatically.

The result view (``show``) renders the finished job: it plays the podcast MP3
with its WebVTT subtitles and shows the speaker-tagged transcript, and it
displays — and lets you download — every generated image (the three Schaubild
variants and the story slides, shown as a horizontal, scrollable strip in
slide order). Each artifact carries its own status, so a partially successful
run still shows whatever was produced.

For transparency, every artifact lists its complete creation parameters: the
exact system, user and image prompts that produced it, the models, the image
sizes and the voices used.

.. _usage-cli:

CLI command
==========

``nr_repurpose:generate`` runs the **whole pipeline synchronously** for an
existing job, bypassing the async worker. This is useful for ops runs and for
driving an end-to-end test without a consumer running.

.. code-block:: bash
   :caption: Run the pipeline for a job uid

   vendor/bin/typo3 nr_repurpose:generate <jobUid>

.. list-table::
   :header-rows: 1
   :widths: 20 12 68

   * - Argument
     - Required
     - Description
   * - ``jobUid``
     - yes
     - The :sql:`tx_nrrepurpose_domain_model_job` uid to process.

The command has no options. It invokes the same orchestrator the worker uses, so
the status transitions, per-artifact isolation, and idempotency (a job already
in a terminal status is skipped) behave identically. Create the job first — via
the backend *New job* form or directly as a database record — then pass its uid.
