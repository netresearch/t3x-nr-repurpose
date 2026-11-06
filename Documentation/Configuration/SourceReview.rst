.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-source-review:

Automatic source fidelity checks
================================

The optional source review compares generated content with the original
normalized source: its title, source attribution and full extracted text. It first checks the
``ContentBrief`` after analysis, then the final text of each format after
shortening and before rendering, speech synthesis or image generation.
The final check always uses the original source, never the generated brief
as evidence. JSON/schema validation remains a separate structural check.

Enable and configure
--------------------

In :guilabel:`Admin Tools > Settings > Extension Configuration > nr_repurpose`:

* ``sourceReviewMode`` defaults to ``off`` for existing and new installations.
  This stores **not checked**, not a successful verdict. ``observe`` runs the
  checks and shows findings/errors while generation and publication can continue.
  ``enforce`` stops a failed brief before generation and refuses each artifact
  whose final text check did not pass. It also guards approval, scheduling and
  publication of social posts.
* ``sourceReviewConfiguration`` selects a separate nr-llm LLM configuration.
  If empty, ``nr_llm.decision.configuration`` applies. The generator's configuration
  and site-specific generator override are never silently reused. Missing or
  unusable reviewer configuration produces **error**, including in observe mode.
* ``sourceReviewMinimumScore`` defaults to ``0.8``. Both answers must meet it.
  Valid values are greater than zero and at most one; invalid values use one.
  Calibrate this threshold and the reviewer on representative source/output pairs
  before enforcing it. A model's score is not a factual accuracy guarantee.
* ``sourceReviewDataClass`` defaults to ``editorContent``. Set it to the most
  sensitive class present in the sources and candidates processed by this
  installation. nr-llm checks the serving provider's trust zone against it;
  invalid values use ``secretAdjacent``. A source URL or PDF filename does
  not establish the sensitivity of its contents.

The checker uses nr-llm's ``DecisionServiceInterface`` with the versioned profile
``nr_repurpose.source_fidelity`` (version 1). Its two yes/no questions assess
support for factual claims and preservation of necessary qualifications, including
negation, uncertainty, conditions and attribution. ``assertAvailable()`` checks
setup before ``evaluate()``. The request carries the job owner's backend user ID
for budget attribution and ``nr_repurpose/source_review`` as caller source.
It uses nr-llm's routing, guardrails and trust checks; no provider client is added.
The complete source is sent as evidence without additional truncation. If the
chosen reviewer cannot process it, the result is an error, not a partial pass.
Source extraction limits still apply before analysis, as documented for ingestion.

Persistence and editorial approval
----------------------------------

The job stores the brief's assessment in ``source_review``; each artifact stores
its own assessment in a separate field of the same name. Stored details include
status (``not_run``, ``passed``, ``flagged``, ``error``), mode, source and candidate
SHA-256 hashes, profile/version, questions/scores, threshold, data class,
configuration, provider/model, checked scope and evaluation time. Error reasons
are fixed codes; raw exception messages and the original source are not copied
into this assessment record.

A machine assessment never grants editorial approval. The existing human review
status is independent. Enforced publication requires both a passed brief and a
passed artifact assessment against the same original source. It verifies the exact
current ``script_text``, source pointers (including FAL references and recorded
file hashes), the current threshold, profile, data class and reviewer
configuration selection. Editing text or tightening the policy requires a new
assessment; an earlier human approval cannot bypass this check. The publication
claim also compares the scheduled content and assessment with its current row.
This binds publication to the ingested snapshot. It does not fetch a live website
again, so later changes at an unchanged external URL are not detected.

The final source/text check is the authorization point for the external publish
call. The publisher sends that unchanged, checked snapshot with its matching
source URL. It does not hold a database lock across the external call. A source
edit after the check cannot atomically revoke a delivery already in progress.

An enforced run stays enforced when retried, even if the global setting has since
been switched off or to observe. Its previous assessment is retained until the
new check replaces it. Jobs can be requeued only with status ``failed`` and no
completed artifacts. Jobs with status ``partially_done`` have some successful
artifacts and cannot be requeued; create a new job to regenerate and reassess
the content in that case.

If an interrupted job resumes and its brief review fails, previously completed
or approved artifacts are retained, just as after ingestion or analysis failures.
Those retained completed artifacts also prevent requeueing; recovery requires a
new job. There is no automatic repair loop and no UI override of a failed machine
check.
Existing artifacts without an assessment remain visibly **not checked**; enabling
enforce blocks their approval/publication until regenerated. Apply the normal
TYPO3 database schema update to add the two ``source_review`` text fields, and
rebuild the dependency-injection cache when deploying the extension update.

What is and is not checked
--------------------------

Text formats and documents are checked after their final text limits and AI label
have been applied, before file rendering. Each story slide's final headline and
subline are checked before the shared background is produced. A story video checks
the combined copy of only the slides actually rendered. Diagram HTML variants are
checked separately, since their opaque and transparent copy may come from different
completions. For ``html_bg``, the checked candidate combines the visible overlay
copy with the complete background image prompt, including its configured preamble
and selected style/audience snippets. Full AI image generation checks its actual
input prompt.

The podcast checks the shortened dialogue before TTS. If synthesis loses a turn,
the remaining transcript is checked again before stitching, because the omission
can change meaning. The saved candidate hash always refers to that remaining text.

These are text/input checks. They do not inspect finished pixels, synthesized
speech, visual layout, clipping, charts or encoded video. Source fidelity also does
not establish that the original source is true. Human review must still inspect
final media and assess suitability for publication. The displayed automatic
verdict records the last assessment; it is not an unconditional publication permit.
