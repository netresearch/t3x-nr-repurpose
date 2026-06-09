.. include:: /Includes.rst.txt

.. _adr-003:

==================================================
ADR-003: Vault-Backed OpenAI Key via nr-llm
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
be stored as nr-vault identifiers, never as plaintext*, and it would scatter the
secret across configuration and process memory on the worker host.

nr_repurpose also should not own provider code at all: nr-llm already abstracts
OpenAI, enforces per-user budgets, and (since nr-llm ``0.10.0``, see nr-llm
ADR-030) routes both its database-backed providers *and* its specialized
services (TTS, images) through nr-vault's audited secure HTTP client.

.. _adr-003-decision:

Decision
========

Hold the OpenAI key in nr-vault and reach it only through nr-llm by identifier.

1. **One secret, one identifier.** The OpenAI key is stored in nr-vault under
   the identifier ``nr_repurpose_openai`` (``vault:store`` /
   ``vault:rotate``). nr-llm's OpenAI provider is configured with
   ``providers.openai.apiKeyIdentifier = nr_repurpose_openai`` and
   ``defaultProvider = openai``.

2. **All access goes through nr-llm.** Completions use nr-llm's
   :php:`CompletionService`; TTS and images use nr-llm's specialized services,
   wrapped by thin local adapters (:php:`OpenAiSpeechSynthesizer`,
   :php:`DallEImageGenerator`) behind the extension's own
   :php:`SpeechSynthesizerInterface` / :php:`ImageGeneratorInterface`. Since
   nr-llm ``0.10.0`` every one of these authenticates through the vault by the
   same identifier — there is no plaintext ``providers.openai.apiKey`` path.

3. **The secret never surfaces here.** nr-vault resolves, injects, audits, and
   memory-scrubs the secret inside its secure HTTP client. nr_repurpose holds no
   key, logs no key, and has no provider HTTP code; it requires
   :composer:`netresearch/nr-vault` ``^0.8.0`` (the version nr-llm ``0.10.0``
   depends on).

.. _adr-003-consequences:

Consequences
============

- No plaintext OpenAI key exists anywhere in nr_repurpose's configuration, code,
  or runtime memory; upstream calls are audited centrally by nr-vault.
- Installation gains a mandatory step: seed the key into the vault under the
  expected identifier and configure the nr-llm provider to read it (see
  :ref:`installation-openai-key` and :ref:`configuration-nr-llm`).
- nr_repurpose inherits nr-llm's budget enforcement for free on completion
  calls; the specialized TTS/image calls are gated manually against nr-llm's
  :php:`BudgetService` because nr-llm does not run them through its budget
  middleware (see :ref:`architecture-generation-budget`).
- The extension is bound to nr-llm ``^0.10.0`` and nr-vault ``^0.8.0``. Adopting
  a different account or provider is a configuration change in nr-llm and the
  vault, not a code change here.
