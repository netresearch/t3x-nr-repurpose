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

.. _usage-ai-label:

AI labelling
------------

Everything this extension generates is marked as AI-generated. A published
podcast or image carries its marker inside the file, so it can be detected as
synthetic (EU AI Act, Art. 50(2)). A text is different: once an editor copies
it, it carries nothing machine-readable. Its only machine-readable marker is
the ``aiLabel`` block in the artifact's database row, and the optional closing
line is human-readable only. **Whoever publishes a generated text has to
disclose it as AI-generated where it is published** — in the page, the
newsletter tool or the social network.

The result view shows an "AI-generated" badge on every finished artifact and
on the story strip when at least one slide finished. The markers:

.. list-table::
   :header-rows: 1
   :widths: 22 78

   * - Artifact
     - Marker
   * - Podcast MP3
     - An ID3v2.3 tag with ``TXXX:AI-generated = true``,
       ``TXXX:DigitalSourceType`` (IPTC ``trainedAlgorithmicMedia``) and a
       ``COMM`` comment naming this extension and the speech model. The
       encoder's ``TSSE`` (ffmpeg's ``Lavf…``) is kept. Media players and tools
       such as ``ffprobe`` show these tags.
   * - Podcast subtitles (WebVTT)
     - A ``NOTE`` block right after the ``WEBVTT`` header naming the AI
       origin. Players ignore ``NOTE`` blocks; the cues are unchanged.
   * - Schaubild and story PNGs
     - ``tEXt`` chunks ``Software`` and ``Comment`` and an XMP packet (``iTXt``
       ``XML:com.adobe.xmp``) with ``Iptc4xmpExt:DigitalSourceType``: the full
       AI image is ``trainedAlgorithmicMedia``, the HTML renders (AI-written
       copy in the branded template, optionally on an AI background) are
       ``compositeWithTrainedAlgorithmicMedia``. With the ``aiLabelImages``
       setting on (the default), the HTML renders also show a small
       "AI-generated" label in the top corner. An image that already carries a
       C2PA manifest is stored unchanged: any change would break the C2PA
       content hash binding, so the manifest would fail validation. Such a
       manifest from the image model is expected to declare the AI origin
       itself; that has not been checked against real output yet (see
       :ref:`adr-005`). A PNG that is incomplete (truncated render) is not
       stored; the artifact fails with the reason.
   * - Every stored file (MP3, WebVTT, PNG)
     - The file's metadata description in the file list: "AI-generated with
       nr_repurpose …", with the digital source type and the known models.
   * - Every artifact, text formats included
     - An ``aiLabel`` block in the artifact metadata: ``aiGenerated: true``,
       ``generator``, ``digitalSourceType`` and, where known, ``models``. The
       text formats name no model, because nr-llm does not report which
       model answered a completion. Rows stored before this version have no
       block; their badge says only "Created with generative AI.".
   * - Text formats (optional)
     - With the ``aiLabelTexts`` setting on, the copy-ready text ends with
       "This text was created with AI." in the text's language — a
       human-readable disclosure, not a machine-readable marker. The FAQ
       JSON-LD is never changed, so it stays valid schema.org.

The two settings are described in :ref:`configuration-ai-label`. The file
markers survive a download; they do not survive tools that strip metadata,
such as most social networks' image upload or a re-export in an image editor.

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
