.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-006:

===================================================
ADR-006: Document Formats as HTML Printed to PDF
===================================================

:Status: Accepted
:Date: 2026-09-26
:Authors: Netresearch DTT GmbH

.. _adr-006-context:

Context
=======

Two formats are meant to leave the backend as a file: a slide deck to present
and a handout to print. Their content is structured text, like the text
formats of :ref:`ADR-004 <adr-004>`, but the editor needs a finished document,
not only text to paste.

The extension already drives Chromium through Playwright to render HTML to PNG
(:ref:`ADR-002 <adr-002>`). Chromium also prints HTML to PDF, and the CSS paged
media rules (``@page`` size and margins, ``break-after``) decide page size and
page breaks. A PDF library in PHP (TCPDF, mPDF, Dompdf) would be a new
dependency with its own layout model and a partial CSS implementation.

Every stored file must carry the machine-readable AI label
(:ref:`ADR-005 <adr-005>`). Chromium writes only ``Title``, ``Creator`` and
``Producer`` into the PDF; it ignores ``<meta>`` description and keywords.

.. _adr-006-decision:

Decision
========

**The document formats are text formats that also print a PDF.** A slide deck
and a handout are written by one schema-validated completion each, exactly as
in ADR-004, and stored with ``script_text`` and ``metadata.content``. The
abstract :php:`AbstractDocumentGenerator` then renders the content through a
branded Fluid template (``Resources/Private/Templates/Generated/SlideDeck`` and
``Handout``, one per theme) and prints it with ``render.cjs --pdf``, which calls
``page.pdf()`` with ``printBackground`` and ``preferCSSPageSize``. The slide
deck declares ``@page { size: 1920px 1080px }``, the handout
``@page { size: A4 }``. The PDF is stored in FAL (``file_uid``) and the HTML it
was printed from in ``source_html``.

**The PDF is labelled by an incremental update, written in PHP.**
:php:`AiContentMarker::markPdf()` appends to the file, and leaves the bytes
Chromium wrote unchanged: a new document information dictionary with the old
entries plus ``Subject`` (the AI statement), ``Keywords``, ``AIGenerated`` and
``DigitalSourceType``; the catalog with a ``/Metadata`` stream holding the same
XMP packet as the PNG files (``Iptc4xmpExt:DigitalSourceType``); and a
cross-reference section for these three objects with ``/Prev`` pointing at
Chromium's table. Only a complete, unencrypted PDF with a classic
cross-reference table is accepted, which is what Chromium 153 writes (PDF 1.4,
no object streams). Any other file is refused, and the artifact fails, as for a
truncated PNG.

**A failed print fails the row.** The text alone is not the format the editor
asked for; the error names the render failure ("file error").

.. _adr-006-consequences:

Consequences
============

●  No new PHP dependency; the layout lives in HTML and CSS next to the other
   generated templates, and a theme is one more template file.

●  The label is readable by standard tools: checked with ``qpdf --check``
   (no errors), ``pdfinfo`` (metadata stream present, pages unchanged) and
   ``exiftool`` (``XMP-iptcExt:DigitalSourceType``).

◐  The labelling depends on Chromium's PDF layout. A Chromium that writes a
   cross-reference stream or object streams makes every document artifact fail
   with "the PDF has no classic cross-reference table" instead of storing an
   unlabelled file. That failure is the signal to extend the marker.

◐  Headings, bullets and slide counts are capped in the generator, not only
   in the prompt, because a slide has a fixed size. Text that still does not
   fit is cut by the page (``overflow: hidden``), not reflowed.

✕  The documents need Chromium on the worker, like the Schaubild and story
   renders. Without it the text is stored nowhere either, because the row
   fails.
