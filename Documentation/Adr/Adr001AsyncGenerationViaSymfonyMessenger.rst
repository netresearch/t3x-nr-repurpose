.. include:: /Includes.rst.txt

.. _adr-001:

==============================================================
ADR-001: Asynchronous Generation via Symfony Messenger
==============================================================

:Status: Accepted
:Date: 2026-06-09
:Authors: Netresearch DTT GmbH

.. _adr-001-context:

Context
=======

A single generation run is long and I/O-bound: it fetches and analyzes a source
(one or more nr-llm completion calls, possibly map-reduced), then synthesizes a
multi-turn podcast (one TTS HTTP call per turn), generates several AI images,
and drives headless Chromium and ffmpeg to render and stitch the results. End to
end this is far longer than an acceptable backend HTTP request, and it must not
block the editor who submitted the job.

The work also needs to be resumable and observable: an editor submits a job and
then watches its progress in the list view, so the job's state has to live in
the database, not in a request's memory.

.. _adr-001-decision:

Decision
========

Split submission from execution over a Symfony Messenger transport.

1. **The job row is the source of truth.** The backend ``create`` action (via
   :php:`JobSubmissionService`) persists the :php:`Job`, flushes to obtain its
   uid, and dispatches a :php:`GenerateArtifactsMessage` that carries **only**
   the uid. The worker re-reads every input from the database, so the message is
   immutable and minimal and there is no risk of a stale payload.

2. **A long-lived worker executes the pipeline.** The message is routed to an
   asynchronous transport (the doctrine transport in the dev setup); a
   ``messenger:consume`` worker runs :php:`GenerationOrchestrator::process()`.
   The orchestrator updates the job's ``status`` / ``progress`` /
   ``current_step`` as it advances (``queued → ingesting → analyzing →
   generating → done|partially_done|failed``), which is exactly what the list
   view renders.

3. **The handler records failures instead of rethrowing.** TYPO3 v14.3 Core
   ships no retry/failure transport. So :php:`GenerateArtifactsHandler` wraps the
   orchestrator in a ``try/catch``: on a throwable it logs and calls
   :php:`JobProcessingRepository::markFailed()`, and deliberately does **not**
   rethrow. Rethrowing would let Messenger drop the message with no record,
   leaving the job stuck in a non-terminal state forever.

4. **Processing is idempotent.** The orchestrator returns early if the job is
   missing or already in a terminal status, so redelivery (or a manual
   re-dispatch) never reprocesses or duplicates artifacts.

The same orchestrator is reachable synchronously via the
``nr_repurpose:generate`` CLI command, so ops and tests can run the exact
pipeline without a consumer.

.. _adr-001-consequences:

Consequences
============

- Submission returns immediately; the editor watches progress in the list view
  while the worker does the work. Generation time is decoupled from the request
  lifecycle.
- Operating the extension requires running and supervising a worker process
  (restart loop, time/memory limits) on a host that has the rendering binaries —
  an explicit deployment requirement (see :ref:`installation-worker`).
- Because the handler never rethrows, Messenger's own retry/DLQ mechanisms are
  not exercised; failure handling lives entirely in the job row. This is a
  deliberate trade-off for v14.3 Core, which has no failure transport. If a
  later Core version adds one, the handler can be revisited.
- A crashed run always leaves a ``failed`` job with an error message rather than
  a silently lost message.
