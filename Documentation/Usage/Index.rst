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

The landing view lists the jobs of all storage pages, newest first, 25 per
page. Administrators and users whose backend group grants *Approve artifacts*
see every job; any other user sees the jobs they created, and the result view
of another user's job refuses them. Each row shows the source, the selected artifacts, and the live status as
the worker advances it: ``queued → ingesting → analyzing → generating → done``
(or ``partially_done`` / ``failed``). From here you open the *New job* form or
a job's result view.

With more than 25 jobs, a pager below the table shows the record range
(*Records 1 - 25*), links to the first, previous, next and last page, and a
page-number field: enter a number and press :kbd:`Enter` to open that page. A
page number beyond the last page shows the last page.

.. figure:: /Images/Usage/JobList.png
   :alt: Repurpose job list with the New job and Social planning buttons, jobs
       30 to 6 with source URL, status badge, artifact icons, a progress bar and
       a Details button each, and the pagination below the table
   :zoom: lightbox
   :class: with-border with-shadow

   The first of two pages of the job list. A long source URL is cut with an
   ellipsis; the full URL is in the tooltip. The list, the result view and the
   social planning show the URL without user name, password, query and
   fragment; the job record in the List module keeps it as entered.

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
   * - Also as video (in the story card)
     - checkbox (off by default)
     - Turns the story slides into one silent MP4 (4 seconds per slide, slow
       zoom, cross-fades). Only with the story.
   * - Executive summary / FAQ / Social posts / Newsletter
     - checkboxes (all off by default)
     - Which text formats to generate this run. They are opt-in, so an
       upgraded installation makes no additional LLM calls until an editor
       ticks one. *Audience* and *tone of voice* steer them; persona, layout
       and style do not apply to text.
   * - Slide deck / Handout
     - checkboxes (both off by default)
     - Which documents to print as PDF this run (see
       :ref:`usage-documents`). Steered like the text formats.

.. note::

   For a *PDF file (FAL)* source, upload the PDF as a ``sys_file`` and attach it
   to the job record via the record edit view; the *New job* form sets the
   source type, URL and extraction mode.

.. figure:: /Images/Usage/JobNew.png
   :alt: New job form with source type, source URL, PDF extraction mode, theme,
       audience and tone of voice selects, the Podcast, Schaubild and Story
       cards with their persona, layout and style selects, and the text and
       document format checkboxes
   :zoom: lightbox
   :class: with-border with-shadow

   The *New job* form. The snippet selects offer the nr-llm prompt snippets of
   their tag; without snippets they offer only "(none)".

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
slide order) and plays the story video. Each artifact carries its own status,
so a partially successful run still shows whatever was produced.

For transparency, every artifact lists its complete creation parameters: the
exact system, user and image prompts that produced it, the models, the image
sizes and the voices used.

.. figure:: /Images/Usage/JobResult.png
   :alt: Result view of job 30 with source, status and creation parameters, the
       podcast card with audio player, download buttons and the expanded
       generation parameters, and the first Schaubild variant with its preview
   :zoom: lightbox
   :class: with-border with-shadow

   The top of a result view: the podcast with its generation parameters opened,
   followed by the first Schaubild variant.

.. figure:: /Images/Usage/JobResultStory.png
   :alt: Story card with five 9:16 slides side by side, each with its slide
       number, a Download PNG link and its review state
   :zoom: lightbox
   :class: with-border with-shadow

   The story slides as a horizontal strip in slide order.

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
gets the English labels. When the answer is unusable — the provider fails, the
answer does not match the JSON shape after nr-llm's one repair round, or a
required part is
empty — the artifact is marked failed with the reason, and the other artifacts
of the job are not affected. The text formats make no speech or image call, so
they need neither the ``generate_audio`` nor the ``generate_vision``
permission.

.. _usage-documents:

Documents
---------

The slide deck and the handout are written like a text format and then
printed to a PDF by Chromium (see :ref:`adr-006`). The result view has
:guilabel:`Open PDF` and :guilabel:`Download PDF` and shows the content below;
``script_text`` holds the outline of the deck or the text of the handout.

.. list-table::
   :header-rows: 1
   :widths: 18 32 50

   * - Format
     - PDF
     - Rules enforced in code
   * - Slide deck
     - 1920×1080 CSS pixels per page: title slide, content slides, closing
       slide
     - At most eight content slides, five bullet points each; headings are
       cut at 80 and bullet points at 140 characters, because a slide has a
       fixed size. A slide without a heading or bullet point is dropped.
   * - Handout
     - A4, 18 mm margins
     - At most five sections with two paragraphs each and six key facts. A
       title and a lead are required.

Both follow the job's theme (Netresearch CI or neutral). With the
``aiLabelTexts`` setting on, the closing line is printed on the last slide or
at the foot of the handout. Every PDF carries the machine-readable AI label
(see below). When the print fails, the artifact fails with "file error" and
the reason; the documents need Chromium on the worker, as the images do.

.. _usage-review:

Approval and social planning
----------------------------

Every finished artifact shows its review state: *Not reviewed*, *Approved* or
*Rejected*. Users whose backend groups grant *Approve artifacts* (and
administrators) see :guilabel:`Approve` and :guilabel:`Reject` next to it.

An approved social post gets a date and time field and :guilabel:`Schedule`.
The post is sent when the command ``nr_repurpose:publish-due`` next runs after
that time, through the webhook in the extension configuration (see
:ref:`configuration-social`). :guilabel:`Remove from schedule` takes it off
again; rejecting a scheduled post does the same. A published post keeps its
review and schedule.

.. figure:: /Images/Usage/JobReview.png
   :alt: Three social post cards: LinkedIn approved and scheduled for 2026-10-01
       09:00 with Publish at field, Schedule and Remove from schedule; X
       approved and published; Instagram not reviewed with Approve and Reject
   :zoom: lightbox
   :class: with-border with-shadow

   Three social posts: approved and scheduled, approved and published, and not
   yet reviewed.

:guilabel:`Social planning` in the job list shows every scheduled, published
and failed post of the jobs you see, oldest time first, with the channel's reason
for a failure and a notice when no webhook is configured.

.. figure:: /Images/Usage/SocialPlanning.png
   :alt: Social planning view with the No publishing channel notice and a table
       of six posts with publish time, platform, post text, status (published,
       failed with the webhook's reason, scheduled) and the job they belong to
   :zoom: lightbox
   :class: with-border with-shadow

   The social planning view without a configured webhook.

Generating a job again replaces its artifacts, and with them their reviews and
schedules.

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
   * - Slide deck and handout PDFs
     - An update appended to the PDF Chromium printed: the document
       information gets ``Subject`` (the AI statement), ``Keywords``,
       ``AIGenerated`` and ``DigitalSourceType`` (``trainedAlgorithmicMedia``),
       and the catalog an XMP packet as ``/Metadata`` with
       ``Iptc4xmpExt:DigitalSourceType``. ``pdfinfo`` and ``exiftool`` show
       them. A PDF that is not complete or not in Chromium's layout is not
       stored; the artifact fails with the reason (see :ref:`adr-006`).
   * - Story video (MP4)
     - MP4 keys written by ffmpeg when it encodes the video: ``comment`` (the
       AI statement), ``AIGenerated = true`` and ``DigitalSourceType``
       (``compositeWithTrainedAlgorithmicMedia``); ``ffprobe`` and
       ``exiftool`` show them. The slides it is made from carry the visible
       label when ``aiLabelImages`` is on.
   * - Every stored file (MP3, WebVTT, PNG, PDF, MP4)
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
