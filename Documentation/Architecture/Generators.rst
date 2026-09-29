.. include:: /Includes.rst.txt

.. _architecture-generators:

==========
Generators
==========

How the generators of :ref:`Stage 3 <architecture-generation>` spend on AI
calls, attribute their cost, and build each artifact.

.. _architecture-generation-budget:

Two AI cost paths
=================

nr-llm guards *completion* calls with its budget middleware automatically (via
the ``beUserUid`` on :php:`ChatOptions`). Its *specialized* services (TTS,
image) are **not** middleware-guarded, so before spending on them each generator
calls :php:`AbstractGenerator::specializedAllowed()`, which checks nr-llm's
:php:`BudgetService` and the service's ``isAvailable()``. A budget-starved run
therefore still yields the cost-free variants (for example the deterministic
HTML Schaubild) while skipping the AI-image variants.

.. _architecture-generation-attribution:

Cost attribution
================

Every nr-llm call names this extension and the pipeline step it belongs to
(:php:`AbstractOptions::withCallerSource()`), so nr-llm's Analytics module breaks
usage and cost down by ``nr_repurpose`` and by operation instead of listing them
as "Unattributed". The operation names are constants on
:php:`Netresearch\NrRepurpose\Service\CallerSource`:

.. code-block:: text

   analyzeDocument        document synthesis (and its corrective retry)
   analyzeDocumentChunk   map step, one call per chunk of a large document
   extractPdfVision       OCR of one rasterized PDF page
   generatePodcast        podcast dialogue script
   generateDiagram        Schaubild diagram body
   generateStory          story carousel copy
   generateExecSummary    executive summary
   generateFaq            FAQ question/answer pairs
   generateSocialPost     social posts, all platform variants in one call
   generateNewsletter     newsletter text
   generateSlideDeck      slide deck titles and bullet points
   generateHandout        handout title, lead, sections and key facts

:php:`ConfiguredCompletionService`, the decorator every text completion passes
through, stamps the extension key on options that carry none, so a new call site
is attributed even if it forgets. The operation stays with the call site — only
it knows which step it is.

The specialized calls (TTS, image generation) carry no attribution: nr-llm's
specialized services do not read the caller source. The PDF vision annotation is
set but does not reach the telemetry row either, because
:php:`VisionService::analyzeImageFull()` rebuilds the options object without it
(`netresearch/t3x-nr-llm#845 <https://github.com/netresearch/t3x-nr-llm/issues/845>`__).

.. _architecture-generation-podcast:

Podcast
=======

:php:`PodcastGenerator` asks the completion service for a dialogue script sized
to the document scope, spoken by the job's selected personas (one to three, each
with its name, character description and optional own TTS voice) or by the
default hosts (Host A = ``nova``, Host B = ``onyx``). Each
turn is one specialized TTS call producing an MP3 segment, with a single retry
on a transient failure and a skip (rather than a whole-episode failure) if a
turn still fails. The segments are concatenated by
:php:`FfmpegAudioStitcher::concat()` (ffmpeg ``concat`` demuxer, stream copy, no
re-encode); per-segment durations are read with ``ffprobe`` and fed to
:php:`WebVttBuilder` so the subtitle cue times match the audio. The MP3 and the
``.vtt`` are stored in FAL; the speaker-tagged transcript is kept on the
artifact row.

.. _architecture-generation-schaubild:

Schaubild
=========

:php:`SchaubildGenerator` produces three artifact rows for empirical comparison:

-   ``html`` — the LLM writes a branded HTML diagram body; it is wrapped in the
    theme template and rendered opaque to PNG by Chromium. No specialized call,
    so this variant always proceeds.
-   ``html_bg`` — an AI background image plus the same diagram rendered
    transparent, composited together (see :ref:`architecture-rendering`).
-   ``ki_image`` — a full AI text-to-image from a content-derived prompt.

The diagram is rendered at 1200 px wide, auto-height.

.. _architecture-generation-story:

Instagram story
===============

:php:`StoryGenerator` asks the completion service once for the whole carousel —
a cover slide, one slide per key point (at most four) and an outro with the
source attribution, capped at six slides; the planned cost scales with the
expected slide count. Each slide is rendered from the branded 9:16 template
(1080×1920) into its own artifact row (variant ``slide-N``; slide role, index
and total in the metadata), so a failed slide render fails only that slide.
When the image service is available and within budget one portrait AI
background is generated and composited behind every slide; otherwise the
slides fall back to flat renders.

.. _architecture-generation-text-formats:

Text formats
============

:php:`ExecutiveSummaryGenerator`, :php:`FaqGenerator`,
:php:`SocialPostGenerator` and :php:`NewsletterGenerator` extend
:php:`AbstractTextGenerator`. Each makes one
:php:`CompletionServiceInterface::completeStructured()` call with its own JSON
schema; nr-llm validates the answer against the schema and asks once more with
the validation failure when it does not match. The generator then applies what
a schema cannot express — caps, the platform character limits
(:php:`TextLimiter`, cut at a sentence boundary, or at a word boundary when
that would keep less than half), hashtag normalisation — and
stores the plain text in ``script_text`` and the structured answer in
``metadata.content``. The format's task sits in the system prompt; the user
prompt carries only the source-derived brief as untrusted data inside
``<source_material>`` tags, with tag-like ``<source…`` sequences in the data
neutralised. The social posts become one row per platform variant
(``linkedin``, ``x``, ``instagram``); the other formats one row each. No file
is written to FAL. See :ref:`adr-004`.
