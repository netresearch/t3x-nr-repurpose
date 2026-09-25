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
       Up to three *personas* define the podcast speakers (name, character,
       optional own voice); *layout* and *style* shape the AI imagery — a
       layout's ``imageSize`` metadata sets the image dimensions
       (see :ref:`configuration-snippets`).
   * - Podcast / Schaubild / Story
     - checkboxes (all on by default)
     - Which artifacts to generate this run.
   * - Executive summary / FAQ / Social posts / Newsletter
     - checkboxes (all off by default)
     - Which text formats to generate this run. They are opt-in, so an
       upgraded installation makes no additional LLM calls until an editor
       ticks one. *Audience* and *tone of voice* steer them; persona, layout
       and style do not apply to text.

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

.. _usage-text-formats:

Text formats
------------

Each text format is written by one LLM call that must answer in a fixed JSON
shape (see :ref:`adr-004`). The result view renders the structured answer; the
plain-text version of every text is stored on the artifact as well
(``script_text``), ready to copy.

.. list-table::
   :header-rows: 1
   :widths: 18 32 50

   * - Format
     - Result view
     - Rules enforced in code
   * - Executive summary
     - one paragraph
     - At most eight sentences (the first eight are kept). A shorter answer is
       kept rather than padded: a thin source may not carry five sentences of
       facts.
   * - FAQ
     - a definition list of questions and answers, plus the schema.org
       ``FAQPage`` JSON-LD in a collapsible block
     - At most ten pairs; a pair without a question or an answer is dropped.
       The JSON-LD names the text's language (``inLanguage``) and escapes
       ``<`` and ``>``, so it can be pasted into a
       ``<script type="application/ld+json">`` element as it is.
   * - Social posts
     - one card per platform (``linkedin``, ``x``, ``instagram``) with the
       character count
     - LinkedIn ≤ 3,000, X ≤ 280, Instagram ≤ 2,200 characters including the
       hashtag line. A longer post is cut at the last sentence end inside the
       limit; when that would keep less than half the limit, it is cut at a
       word boundary with "…" instead. The card says which of the two
       happened. Each hashtag entry is split on spaces and ``#``, reduced to
       letters, digits and underscores (``AI-driven`` → ``#AIdriven``) and
       de-duplicated; at most 30 are kept, and more are left out while the
       hashtag line would take more than half of the 2,200 characters. The card
       says how many were left out.
   * - Newsletter
     - subject, preheader, body paragraphs and the call to action
     - Subject, preheader, at least one paragraph and exactly one call to
       action are required.

Characters are counted as Unicode code points. LinkedIn and Instagram count
the same way; X weighs some characters double (most emoji, CJK), so a post in
those scripts can still exceed X's own limit.

The texts are written in the detected source language, like every other
artifact. The labels inside the plain text (``Q:``/``A:`` for the FAQ,
``Subject:``/``Preheader:`` for the newsletter) follow that language too, not
the editor's backend language; a language the extension has no translation for
gets the English labels. When the answer is unusable — the provider fails, the answer does not
match the JSON shape after nr-llm's one repair round, or a required part is
empty — the artifact is marked failed with the reason, and the other artifacts
of the job are not affected. The text formats make no speech or image call, so
they need neither the ``generate_audio`` nor the ``generate_vision``
permission.

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
