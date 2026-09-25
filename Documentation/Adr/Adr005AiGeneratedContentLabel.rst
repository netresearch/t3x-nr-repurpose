.. include:: /Includes.rst.txt

.. _adr-005:

========================================================
ADR-005: AI Label on Every Artifact, Embedded at Storage
========================================================

:Status: Accepted
:Date: 2026-09-25
:Authors: Netresearch DTT GmbH

.. _adr-005-context:

Context
=======

Every artifact of this extension is synthetic: TTS voices reading an
LLM-written script, images rendered from LLM-written copy or drawn by an image
model, and texts written by an LLM. The EU AI Act, Art. 50(2), requires the
output of such systems to be "marked in a machine-readable format and
detectable as artificially generated or manipulated". The artifacts leave the
backend as files an editor downloads (MP3, WebVTT, PNG) and as text an editor
copies, so the marker has to travel with the file, not only live in the
database.

The formats and tools at hand:

- The podcast MP3 is written by ffmpeg's concat demuxer
  (``FfmpegAudioStitcher``). Measured with ffmpeg 7.1: the output starts with
  an ID3v2.4 tag holding only ffmpeg's own ``TSSE`` frame, whatever the input
  segments carried.
- The Schaubild ``html`` variant and flat story slides are PNGs written by
  Chromium (``render.cjs``); ``html_bg`` and story slides on an AI background
  are re-encoded by the GD compositor, which drops every ancillary chunk. The
  ``ki_image`` variant is the image model's PNG as returned (nr-llm sends no
  ``output_format``, and gpt-image models default to PNG).
- The extension has no image- or audio-metadata library as a dependency, and
  the repository asks to add none without cause. getID3 (MP3) or a
  PNG/XMP toolkit would cover far more than the three chunk types and four
  frames needed here.
- ``sys_file_metadata`` in TYPO3 core has ``title``, ``description`` and
  ``alternative``; the extension does not extend core tables.

.. _adr-005-decision:

Decision
========

1. **One provenance value per artifact.** :php:`AiProvenance` carries the
   generator (``nr_repurpose <version>`` as TYPO3 reports it), the IPTC
   digital source type and the models this extension knows. Every generator
   builds it and stores its ``toArray()`` as the ``aiLabel`` block of the
   artifact metadata: ``aiGenerated: true``, ``generator``,
   ``digitalSourceType`` and, where known, ``models``.

2. **IPTC digital source type as the vocabulary.** The full AI image, the
   podcast and the texts are ``trainedAlgorithmicMedia``; the HTML renders —
   LLM-written copy, optionally on an AI background, set into the
   human-made branded template — are
   ``compositeWithTrainedAlgorithmicMedia``. It is the vocabulary the IPTC
   Photo Metadata Standard and C2PA use for synthetic media.

3. **The marker is embedded at storage, in PHP.** :php:`JobFileStorage::store()`
   takes the provenance and passes the bytes through :php:`AiContentMarker`
   before writing them to FAL — after the last re-encode, so nothing in the
   pipeline can drop the marker again:

   - PNG: ``tEXt`` ``Software`` and ``Comment`` plus an ``iTXt``
     ``XML:com.adobe.xmp`` packet with ``Iptc4xmpExt:DigitalSourceType``,
     ``xmp:CreatorTool`` and ``dc:description``, inserted right after
     ``IHDR``. The image data is not touched.
   - PNG with a ``caBX`` chunk (a C2PA manifest): left byte-identical. Any
     change would invalidate the manifest's signature, and the manifest
     already declares the origin.
   - MP3: an existing ID3v2 tag is replaced by an ID3v2.3 tag with
     ``TXXX:AI-generated = true``, ``TXXX:DigitalSourceType``, ``TSSE`` and a
     ``COMM`` frame. Replacing rather than merging keeps the writer to one tag
     version; the only frame lost is ffmpeg's ``TSSE`` (its muxer version).
     ID3v2.3 because it is the version every player reads.
   - A file that does not match its extension's format (a ``.png`` that is not
     a PNG, a ``.mp3`` without an MPEG frame sync) makes the store fail, so
     the artifact fails instead of being stored unlabelled.
   - Other files (the WebVTT subtitles) are not rewritten.

4. **FAL description, core field only.** For every file stored with a
   provenance, ``sys_file_metadata.description`` states the AI origin in one
   sentence. No column is added to a core table. Updating the metadata record
   the indexer created goes through ``MetaDataAspect::save()`` — marked
   ``@internal``, but it is what core's own indexer calls and the only update
   path; the PHPStan exception is scoped to that one call.

5. **Visible labels, two settings.**

   - The result view shows an "AI-generated" badge on every finished artifact,
     rows stored before this change included — they are just as generated.
   - ``aiLabelImages`` (default on) renders a small corner label into the
     Schaubild and story templates, in the artifact's language, on its own dark
     plate so it stays legible over an AI background. ``ki_image`` never passes
     the HTML renderer and gets no visible label; drawing one onto the model's
     image would also break a C2PA manifest.
   - ``aiLabelTexts`` (default off) appends "This text was created with AI." in
     the text's language to the copy-ready text. Off by default because these
     texts are pasted into CMS pages, newsletter tools and social networks that
     carry their own AI disclosure, where a second one in the body is noise.
     The social posts reserve the line's length inside their platform limit.
     Only ``script_text`` changes; the structured content and the FAQ JSON-LD
     stay as they are, so the JSON-LD remains valid schema.org.

   The settings are resolved once per run by the orchestrator
   (:php:`AiLabelSettingsFactory`) into the :php:`GenerationContext`, together
   with the label texts in the artifacts' language.

.. _adr-005-consequences:

Consequences
============

- Every file downloaded from the result view carries its AI origin, readable
  by standard tools (``ffprobe``, ``exiftool``, Pillow, media players), and
  every artifact row states it in the metadata.
- The markers are metadata. Tools that strip metadata — most social networks'
  image upload, a re-export in an image editor — remove them; the visible
  corner label on the rendered images survives those, the full AI image has
  none.
- The text formats name no model: nr-llm's completion API returns the answer,
  not the model that produced it.
- No C2PA manifest is written. Signing needs a key and a trust anchor this
  extension does not have; an image model's own manifest is preserved.
- **Assumption, unverified:** that the image model returns a C2PA manifest in
  a ``caBX`` chunk. If it does not, ``ki_image`` gets this extension's
  markers like every other PNG; if it does, the manifest is kept and the
  ``aiLabel`` metadata still states the origin. Either way the image is
  labelled. The first real ``ki_image`` with a ``caBX`` chunk settles it.
