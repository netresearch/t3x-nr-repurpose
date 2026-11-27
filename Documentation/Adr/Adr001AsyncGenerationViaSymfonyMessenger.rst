.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

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
   ``current_step`` as it advances through the statuses
   ``queued → ingesting → analyzing → generating → done|partially_done|failed``.
   The list view renders ``status`` and ``progress``; the detail view adds
   ``current_step``.

3. **The handler records failures instead of rethrowing.** TYPO3 v14.3 Core
   ships no retry/failure transport. So :php:`GenerateArtifactsHandler` wraps the
   orchestrator in a ``try/catch``: on a throwable it logs and calls
   :php:`JobProcessingRepository::markFailed()`, and deliberately does **not**
   rethrow. Rethrowing would let Messenger drop the message with no record,
   leaving the job stuck in a non-terminal state forever.
   A crash after the run claimed the job (see point 5) is caught by the
   orchestrator, which marks the job failed through that claim; the handler's
   catch is left with failures before the claim.

4. **Processing is idempotent.** The orchestrator returns early if the job is
   missing or already in a terminal status, so redelivery (or a manual
   re-dispatch) never reprocesses or duplicates artifacts.

5. **One run owns a job.** The doctrine transport hands a message out again
   while its first delivery still runs once its ``redeliver_timeout`` (3600
   seconds) has passed, and after a worker died. Before ingestion a run claims
   the job: it writes a random token and the time into ``run_token`` and
   ``run_claimed_at``, in one statement that only an unfinished job passes
   whose claim is empty or older than the lease of 1800 seconds. Every later
   write of the run (status, progress, failure, source review, artifact rows)
   is bound to that token and renews the claim. ``RunClaimRenewalMiddleware``,
   the innermost layer of nr-llm's provider middleware pipeline, renews it
   before every provider call (completion, structured output, vision,
   decision, speech, image), and the PDF ingestion before every page, which
   poppler reads without a provider call. A run whose token is
   gone stops and writes nothing further; a finished job holds no claim, and a
   failed job that is queued again starts without one. A failure before the
   claim is recorded only on an unfinished job that no live run holds. A
   delivery that
   finds a live claim writes nothing and sends the message again with a delay
   of one lease. When it comes back, the other run has finished and the message
   does nothing, or the other run's worker is gone, its claim has expired and
   the message takes the job over, or the claim is still live and the message
   is sent again. A run that lasts long therefore sees one such message per
   lease. A send that fails is tried three times and then fails the delivery;
   Messenger then rejects the message, which deletes it from the queue, unless
   the rejection fails as well. A live owner still finishes the job; if the
   owner's worker is dead, the job stands still, and after two hours without a
   write the backend offers to start it again, as for a failed job (see
   :ref:`adr-011`). The Messenger handler's own failure write after a crash
   accepts the claim of the run this process just ended, so a run whose
   failure write failed once still ends as ``failed``; it never touches a
   finished job.

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
- The lease is a constant of 1800 seconds. It stays below the transport's
  3600 seconds, so the delivery after a dead worker normally finds the claim
  expired. It has to exceed the longest stretch a live run spends without
  renewing its claim. A run renews it with every write, before every provider
  dispatch, and before every step that bypasses nr-llm's pipeline: each
  external process (Chromium, ffmpeg, ffprobe, poppler), each page fetch of a
  URL or page source, and the PDF download. One provider dispatch is not one
  request: nr-llm retries a failed connection inside it, up to
  ``max_retries`` + 1 attempts, each bounded by the attempt timeout (an LLM
  configuration's effective timeout for a call through it, else the
  provider's ``api_timeout``), with a backoff that doubles from 0.2 up to 30
  seconds per retry (111 seconds for 10 retries); a timed-out attempt is not
  retried. The middleware cannot renew between those attempts
  (`netresearch/t3x-nr-llm#1476
  <https://github.com/netresearch/t3x-nr-llm/issues/1476>`__). Local work
  after a call (Chromium, ffmpeg, poppler) adds up to 300 seconds. nr-llm
  allows up to 10 retries of up to 600 seconds, which no lease below 3600
  seconds covers, so the extension requires ``(max_retries + 1)`` × the
  attempt timeout of at most 1380 seconds for every active nr-llm provider
  and LLM configuration: with the backoff and the local work that stays below
  1800 seconds. The worker logs a warning naming each record above it before
  its first job.
  nr-llm's defaults (3 retries of 120 seconds) and 3 retries of 300 seconds
  fit. A live run that still goes 1800 seconds without a renewal can be taken
  over by the delayed message; it then stops at its next write, and the new
  run starts from the beginning.
- Lease expiry compares ``run_claimed_at``, written with the worker host's
  clock, against the clock of the host that reads it. Worker hosts and web
  hosts need synchronised clocks (NTP); a skew of minutes shortens or extends
  the lease by that much. The time is not taken from the database: SQLite,
  MariaDB and PostgreSQL each spell the current Unix time differently, and
  Doctrine DBAL offers no portable expression for it.
- ``nr_repurpose:generate`` reports its own run: it exits with an error when
  the run failed the job, lost it to another run, found it failed already,
  found no such job or found it held by another run. It exits without error
  when the run finished the job, or found it done or partially done already.
