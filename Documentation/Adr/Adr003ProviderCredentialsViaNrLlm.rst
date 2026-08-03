.. include:: /Includes.rst.txt

.. _adr-003:

==================================================
ADR-003: Provider Credentials Delegated to nr-llm
==================================================

:Status: Accepted
:Date: 2026-06-09
:Authors: Netresearch DTT GmbH

.. _adr-003-context:

Context
=======

Every AI capability nr_repurpose uses — chat/vision completion (analysis,
scripts, copy), text-to-speech (the podcast), and image generation (the diagram
and story backgrounds) — ultimately authenticates with the same OpenAI account.
The naïve approach is to put the OpenAI API key in extension configuration and
hand it to each service. That violates the Netresearch rule that *API keys MUST
be referenced by identifier, never stored as plaintext*, and it would scatter
the secret across configuration and process memory on the worker host.

nr_repurpose also should not own provider code at all: nr-llm already abstracts
OpenAI, enforces per-user budgets, and (since nr-llm ``0.10.0``, see nr-llm
ADR-030) authenticates both its database-backed providers *and* its specialized
services (TTS, images) from its own credential store.

.. _adr-003-decision:

Decision
========

Own no provider code and no key. Give the credential to nr-llm and refer to it
only by the identifier nr-llm issues.

1. **One secret, one identifier.** The key is handed to nr-llm, which stores it
   and yields an identifier (``nr_repurpose_openai`` in the bundled setup).
   nr-llm's OpenAI provider is configured with
   ``providers.openai.apiKeyIdentifier = nr_repurpose_openai`` and
   ``defaultProvider = openai``.

2. **All access goes through nr-llm.** Completions use nr-llm's
   :php:`CompletionService`; TTS and images use nr-llm's specialized services,
   wrapped by thin local adapters (:php:`OpenAiSpeechSynthesizer`,
   :php:`DallEImageGenerator`) behind the extension's own
   :php:`SpeechSynthesizerInterface` / :php:`ImageGeneratorInterface`. Since
   nr-llm ``0.10.0`` every one of these authenticates by the same identifier —
   there is no plaintext ``providers.openai.apiKey`` path.

3. **The secret never surfaces here.** nr_repurpose holds no key, logs no key,
   and has no provider HTTP code. *How* nr-llm protects the credential — today
   an encrypted store behind an audited HTTP client, :composer:`netresearch/nr-vault` —
   is nr-llm's implementation detail and nr-llm's dependency. This extension
   neither requires that package nor names its version range: doing so would
   pin a constraint no code here is written against.

.. _adr-003-consequences:

Consequences
============

- No plaintext OpenAI key exists anywhere in nr_repurpose's configuration, code,
  or runtime memory; upstream calls are audited centrally by nr-llm.
- Installation gains a mandatory step: hand the key to nr-llm and point its
  Provider record and its extension configuration at the resulting identifier
  (see :ref:`installation-openai-key` and :ref:`configuration-nr-llm`). nr-llm's
  backend setup wizard takes the key and issues the identifier; scripted
  installs that cannot drive a wizard fill nr-llm's store from the command line
  instead, which is what the bundled DDEV setup does.
- nr_repurpose inherits nr-llm's budget enforcement for free on completion
  calls; the specialized TTS/image calls are gated manually against nr-llm's
  :php:`BudgetService` because nr-llm does not run them through its budget
  middleware (see :ref:`architecture-generation-budget`).
- The extension is bound to nr-llm ``^0.25`` alone; what backs nr-llm's
  credential store, and in which versions, is nr-llm's contract to state and to
  widen. Adopting a different account or provider is a configuration change in
  nr-llm, not a code change here.
