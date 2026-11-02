.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-nr-llm:

================================================
nr-llm wiring: providers, models, Configurations
================================================

nr_repurpose never talks to an AI provider directly and never picks one itself.
It names nr-llm **Configuration** records (use cases) and lets nr-llm resolve
the model, the provider, the API key, the system prompt, and the usage/cost
attribution. Set everything up in nr-llm's backend module
(:guilabel:`Admin Tools > LLM Management`):

#.  Create a **Provider** whose API key references the key identifier nr-llm
    issued during installation (see :ref:`installation-openai-key`).
#.  Create the **Models** you want to use (or fetch them via nr-llm's model
    discovery), including the specialized ones (image, text-to-speech).
#.  Import the **Configuration** records nr_repurpose declares as presets.
    nr_repurpose ships the three records below as *configuration presets*
    (nr-llm ADR-056): open nr-llm's :guilabel:`Configurations` module and each
    appears as a *pending preset* with its required capabilities — import it with
    a single click. Each imports as a criteria-mode configuration that resolves
    against the models you created; no provider, model or key is baked into the
    preset. Mark the imported ``nr_repurpose_text`` record as the instance
    default. (You may still create the records by hand instead — the identifiers
    below are what the extension looks up.)

.. list-table::
   :header-rows: 1
   :widths: 26 30 44

   * - Configuration identifier
     - Used for
     - Model choice
   * - ``nr_repurpose_text`` *(mark as default)*
     - Analysis and copy: the brief, the podcast script, the diagram body, the
       story copy. The pipeline resolves the instance-default Configuration.
     - Any chat model of any nr-llm provider — OpenAI, Anthropic Claude,
       Google Gemini, Groq, Mistral, Ollama, OpenRouter.
   * - ``nr_repurpose_image``
     - AI imagery (Schaubild backgrounds and full images, story backgrounds).
     - Any model accepted by nr-llm's image services; falls back to
       ``gpt-image-2`` when the record is absent. The record's system prompt
       acts as a style preamble for every image prompt.
   * - ``nr_repurpose_tts``
     - Podcast speech synthesis.
     - Any model of nr-llm's text-to-speech service (currently OpenAI ``tts-1``
       / ``tts-1-hd``); falls back to ``tts-1``.

Swapping a model — or, for text, the provider — is a backend-only change: edit
the Configuration record, no code or deployment involved. Per-model and
per-configuration usage and cost appear in nr-llm's analytics module.

Image and speech calls go through nr-llm's *specialized* services, which
currently cover OpenAI (images, TTS) and fal.ai (images). The extension-side
seam for additional backends is the
:php:`ImageGeneratorInterface` / :php:`SpeechSynthesizerInterface` DI alias in
:path:`Configuration/Services.yaml`.

Keys are always referenced by identifier: both the chat providers and the
specialized services let nr-llm resolve and inject the key — no plaintext key
is ever set here. See :ref:`adr-003`.

.. _configuration-site:

A site's own LLM configuration
==============================

A site can name the LLM configuration its AI calls run on, in nr-llm's site
setting ``nrllm.configuration`` (see the nr-llm manual, chapter "Site
settings"). A job whose source is a page of that site names the site on every
text call: the analysis, the podcast script, the diagram body, the story copy
and the text and document formats. The site is stored with the job when it is
created, so the worker and a later retry use it.

*   While the ``nr_repurpose_text`` record exists, is active and is not
    restricted to backend groups, the text calls run on it. nr-llm treats an
    extension's own configuration as an explicit choice that wins over the
    site. The site then only selects the term lists bound to it.
*   Without that record, the text calls take the instance default, and nr-llm
    replaces it with the configuration the site names. A site that names a
    configuration that does not exist, is disabled or is restricted to backend
    groups fails the job; there is no fallback to the default.
*   A job from a URL or a PDF has no site and keeps the instance default.
*   Images (``nr_repurpose_image``), speech (``nr_repurpose_tts``) and the
    OCR of PDF pages do not use the site's configuration.
*   A page tree whose pages belong to several sites uses the site of the
    first page the job reads.

To let the sites' configurations answer, ``nr_repurpose_text`` has to go:
first mark another configuration as the instance default, one with a model
assigned directly (not chosen by criteria) and without a backend-group
restriction, because nr-llm does not use any other configuration as the
default. Then delete or deactivate ``nr_repurpose_text``. If it is the instance default when it goes,
the installation has none, and URL and PDF jobs, and page jobs of a site that
names no configuration, fail at the analysis.

A running worker keeps what it read: changes to ``nr_repurpose_text`` and to a
site's ``nrllm.configuration`` reach it only after it restarts.

:ref:`adr-012` records the decisions.

.. _configuration-snippets:

Prompt snippets
===============

The *New job* form's *audience*, *tone of voice*, *persona*, *layout* and
*style* selectors are populated from nr-llm's prompt-snippet library (snippets
tagged ``audience``, ``tone_of_voice``, ``persona``, ``layout``, ``style``).
Editors maintain them in nr-llm's backend module; each snippet's description is
shown in the form so the choice is informed. A ``layout`` snippet may carry an
``imageSize`` metadata key (``"WIDTHxHEIGHT"``) that sets the AI-image
dimensions for that channel — e.g. skyscraper ``768x2160``, wide ``2160x768``.

.. _configuration-starter-pack:

The starter pack
----------------

A fresh installation has no snippets, so all five selectors read *(none)*. The
**Content Repurpose Starter** use-case pack installs a small library to start
from: two audiences, two tones of voice, three podcast personas with their own
``voice``, three layouts with their ``imageSize``, and three visual styles.

Install it in nr-llm's *Use Case Packs* module, or from a provisioning script:

.. code-block:: bash
    :caption: Install the starter pack unattended

    vendor/bin/typo3 nrllm:usecasepack:install content-repurpose-starter

The records it creates are ordinary snippets — rename them, rewrite them,
deactivate the ones you do not want. Installing again creates only what is
missing and leaves your edits alone.

The text pipeline needs room for its answers. nr-llm stores 1,000 output tokens
on a configuration unless somebody changes the value, and a reasoning model
counts its thinking against that limit, so the brief of a page tree can come back
empty. The extension therefore asks for at least 16,000 tokens per call when the
``nr_repurpose_text`` record holds a lower value, whichever configuration answers.
nr-llm caps that at the model's output limit where the model states one. ``0`` on
the record leaves the choice to the model. An instance whose model has a smaller
limit and does not state it has to enter that limit on the model record.

The pack's snippets are **not** linked to the ``nr_repurpose_text``
configuration by tag, and that is deliberate. This extension resolves the five
families per job, from the selection in the form. Linking them would make
nr-llm compose every active persona, layout and style into every completion as
well — three speakers the job did not choose, and two contradictory image
sizes. See nr-llm's ADR-186.
