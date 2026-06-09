.. include:: /Includes.rst.txt

.. _adr-002:

==================================================================
ADR-002: Node + Playwright Renderer for Image Composition
==================================================================

:Status: Accepted
:Date: 2026-06-09
:Authors: Netresearch DTT GmbH

.. _adr-002-context:

Context
=======

Two of the three artifacts are images built from branded HTML: the Schaubild
diagram and the Instagram story. The reference Schaubild variant and the flat
story are *exact* renderings of an LLM-authored, theme-wrapped HTML fragment —
every label, number and term must come out pixel-correct, with web fonts and CSS
layout honoured. A PHP imaging library cannot lay out and rasterise arbitrary
HTML/CSS; only a real browser engine can.

The artifacts must also support an AI-background variant: a generated background
image with the HTML text layer composited over it. The text layer therefore has
to be renderable on a transparent canvas so the background shows through.

.. _adr-002-decision:

Decision
========

Render HTML to PNG with a bundled Node script that drives the system Chromium
through Playwright, and composite separately with GD.

1. **A single CommonJS renderer.**
   :path:`Resources/Private/NodeRenderer/render.cjs` reads HTML from **stdin**
   and writes a PNG, taking ``--width``, ``--height`` (an integer or ``auto``),
   ``--scale``, ``--out`` and ``--transparent`` / ``--opaque``. It uses
   ``playwright-core`` only — Chromium is the host's apt binary, located via
   ``CHROMIUM_PATH``, with ``PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1`` so Playwright
   never downloads its own. ``waitUntil: 'networkidle'`` and
   ``document.fonts.ready`` ensure web fonts are loaded before the screenshot.

2. **A PHP boundary that shells out safely.**
   :php:`PlaywrightHtmlToImageRenderer` builds the argv, passes HTML on stdin
   (avoiding argv length limits and shell quoting), and runs the process through
   a :php:`ProcessRunnerInterface` (Symfony Process) seam that unit tests can
   replace. ``height=null`` renders full-page/auto-height (the diagram); a fixed
   height clips to the viewport (the story); ``transparent`` maps to
   ``omitBackground`` for the overlay layers.

3. **Composition stays in PHP with GD.** :php:`GdImageCompositor` overlays the
   transparent foreground PNG onto the AI background. The foreground defines the
   output canvas; the background — whose aspect ratio rarely matches, since
   ``gpt-image-1`` only emits 1:1 / 3:2 / 2:3 — is scaled to *cover*
   (centre-cropped, no distortion), then alpha-composited under the foreground.
   GD is used because Imagick is not installed in this stack.

.. _adr-002-consequences:

Consequences
============

- HTML renderings are pixel-accurate: web fonts, gradients and layout match the
  branded templates, and labels stay exactly as authored.
- A transparent text layer over a cover-scaled AI background gives the
  ``html_bg`` and KI-background variants without distorting the designed layout.
- The runtime gains a hard dependency on a Node.js runtime plus a system
  ``chromium`` binary on every host that generates — including the worker. This
  is documented as an explicit requirement (see :ref:`installation`).
- Each render is a process spawn; it is bounded by a process timeout and a
  ``--no-sandbox`` Chromium launch suited to containerised execution.
- The process boundary (stdin HTML, argv flags, a swappable process runner)
  keeps the renderer fully unit-testable without a browser.
