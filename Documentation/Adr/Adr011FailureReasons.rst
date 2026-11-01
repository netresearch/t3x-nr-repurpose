.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-011:

===========================================
ADR-011: What a Failed Job Tells the Editor
===========================================

:Status: Accepted
:Date: 2026-11-01
:Authors: Netresearch DTT GmbH

.. _adr-011-context:

Context
=======

A job that failed showed its status, the percentage it had reached, the
worker's name for the step and a fixed text, for example
``Failed (20%) — analyzing`` and ``Analysis failed``. The fixed text exists for
a good reason: ``error_message`` is shown to every module user, and an exception
can carry a provider's words, a path or SQL. But it left the editor without an
answer to four questions: what happened, what is left, what can I do, and whom
do I ask.

.. _adr-011-decision:

Decision
========

**A failure stores a reason, not only a text.** ``failure_reason`` on the job
and on the artifact holds a value of the enum ``FailureReason``. The value is
set where the exception is still at hand, by ``FailureClassifier``, which reads
the exception's class and code and never its message. Each value has one fixed
text per part in ``locallang.xlf``, English and German: a short line for the job
list, what happened, what is left, what the editor can do, and when to ask whom.
A test holds every value to every part in both languages.

**No text from outside reaches the editor.** ``error_message`` keeps its rule
and is shown only for the source reasons and ``guardrail``, whose messages are
written for the editor. A job or artifact row from an older version has no
reason; it gets the generic ``unknown`` texts, and its stored text stays unread,
because rows of versions before the fixed-text rule can hold a provider's words.
A job's heading still comes from ``current_step``. The older texts
``N of M formats failed`` and ``All N formats failed`` are recognised, because
this extension wrote them.

**An image or speech failure is read through its wrapper.** The generators wrap
every failure of those services in ``RenderingException``, so the classifier
looks at what the wrapper holds and reads ``rendering`` only when nothing below
it says more. A format card uses its own result and action texts, unless the
reason only ever concerns one format; the others speak of a whole job.

**The line to pass on names job, time and reason.** The full exception stays in
the TYPO3 log; the job number and the time find it there.

**A failed job can run again, once per click.** ``JobSubmissionService::retry()``
sets the job back to ``queued`` in one ``UPDATE`` that only a row with the status
``failed`` passes, and dispatches only when that statement changed a row. Two
clicks or two people start one run. Generation deletes a job's artifacts before
it starts, and ``retry()`` deletes them earlier still, so a run that fails during
reading does not show the old reasons beside the new one.

The button is offered only where a new run can end differently, and only while
the job holds no finished artifact: a new run would delete a result that may
already be reviewed. ``some_formats_failed`` therefore never offers it. A retry
reads the source again and pays for every AI call again; nothing of the failed
run is reused, and the page says so beside the button.

**A job that stands still counts as a failure to explain.** The worker cannot
report its own death. A job that is not finished and has not been written for
two hours shows ``worker_stalled``, and it keeps running if the worker comes back.

.. _adr-011-consequences:

Consequences
============

-   A new failure path has to name a reason: ``failArtifact()`` takes one.
-   ``Tests/Architecture/AiBoundaryTest.php`` allows the namespace
    ``Netresearch\NrLlm\Provider\Exception``. Reading the class of an exception
    an nr-llm service throws is not provider code; adapters stay forbidden.
-   The two columns are new, so an installation has to apply the database schema.
