.. include:: /Includes.rst.txt

.. _adr-004:

===========================================================
ADR-004: Text Formats as Schema-Validated Structured Output
===========================================================

:Status: Accepted
:Date: 2026-09-25
:Authors: Netresearch DTT GmbH

.. _adr-004-context:

Context
=======

Four text formats join the media artifacts: an executive summary, an FAQ,
social-media posts for three platforms and a newsletter text. Unlike the
podcast script or the story copy, which are intermediate material for a render
step, these texts *are* the result. Each has a shape the editor relies on — a
list of question/answer pairs, a subject plus preheader plus one call to
action, one post per platform — and the social posts have hard platform limits
(LinkedIn 3,000, X 280, Instagram 2,200 characters).

The existing generators call :php:`CompletionServiceInterface::completeJson()`
and parse the decoded array tolerantly. That call only guarantees valid JSON; a
missing key or a wrong type surfaces in the parser, and there is no second
chance to get it right. nr-llm (since 0.35, the floor of this extension) also
offers :php:`completeStructured()`: the caller passes a JSON schema from a
strict subset (nr-llm ADR-126), nr-llm validates the answer against it, and on
a mismatch makes one repair round-trip that shows the model its invalid output.

Prompts are advisory. A model asked for "at most 280 characters" regularly
writes 300.

.. _adr-004-decision:

Decision
========

1. **One structured call per format.** Each text generator declares a JSON
   schema inside nr-llm's strict subset and calls ``completeStructured()``
   through the same :php:`ConfiguredCompletionService` as every other text
   call, so the ``nr_repurpose_text`` configuration, the caller-source
   attribution and the budget middleware apply unchanged. The social posts for
   all three platforms come from one call.

2. **The generator validates again, for what a schema cannot say.**
   ``parse()`` trims, drops incomplete entries, caps lists (eight summary
   sentences, ten FAQ pairs, thirty hashtags) and enforces the platform limits
   by cutting at the last sentence end that fits (:php:`TextLimiter`). An
   answer that is unusable despite matching the schema raises
   :php:`InvalidLlmOutputException` with an editor-readable reason.

3. **Lower bounds stay in the prompt.** The prompts ask for five to eight
   summary sentences and five to ten FAQ pairs; the code enforces only the
   upper bounds. Rejecting a four-pair FAQ would fail the artifact for a thin
   source, and asking the model to reach five would invite invented content.

4. **Storage.** Every text row stores its plain-text rendering in
   ``script_text`` (what an editor copies) and the structured answer in
   ``metadata.content`` (what the result view renders). No FAL file is
   written. The social posts are one row per platform variant, like the
   Schaubild variants.

5. **Source material is data, not instructions.** The system prompt holds
   everything the extension decides: the role, the format's task, the output
   rules, the output language (only if it is a well-formed language code) and
   the editor's audience and tone snippets. The user prompt holds only the
   source-derived brief, enclosed in ``<source_material>`` and
   ``</source_material>`` and introduced as untrusted data that must not be
   followed. Inside the data, the ``<`` of every tag-like ``<source…`` or
   ``</source…`` sequence (any case, any whitespace) is replaced with ``‹``,
   so the source can neither close the block nor open a second one, and the
   text stays readable. nr-llm defuses its own fence markers the same way,
   but those helpers are private. The tags are a boundary for the model, not
   a guarantee: the system-prompt rule is what tells the model to treat the
   block as data.

6. **The schemas are tested against nr-llm's validator.** nr-llm refuses a
   schema outside the subset before the first provider call. The test double
   :php:`FakeCompletionService` does not check, so each generator's unit test
   pre-flights its schema with nr-llm's :php:`JsonSchemaValidator`.

.. _adr-004-consequences:

Consequences
============

- A non-conforming answer costs at most one extra completion (the repair
  round) instead of failing immediately. When the repair also fails, the
  artifact fails with nr-llm's message behind the format label.
- The platform limits hold even when the model ignores them; the result view
  shows the character count and says when a post was shortened.
- :php:`JsonSchemaValidator` is ``@internal`` in nr-llm. Only the tests use it
  directly; production relies on ``completeStructured()``, which is part of
  nr-llm's public completion interface.
- The older generators keep ``completeJson()``. Moving them is a separate
  decision: their answers feed a render step whose own fallbacks already absorb
  partial answers.
