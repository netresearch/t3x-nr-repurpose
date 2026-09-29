.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

nr_repurpose has five extension settings of its own (see
:ref:`configuration-extension-settings`). Everything else it needs is wiring it
shares with the host instance: the nr-llm provider, model and Configuration
records, the Symfony Messenger routing, and the outbound HTTP timeouts. In the
bundled DDEV environment the instance-level settings (see
:ref:`configuration-worker`) are written to
:path:`config/system/additional.php` by ``ddev install``; in a real deployment
you place them in your instance configuration.

This page covers the extension settings and the backend permissions; the
nr-llm wiring, the worker environment, and the AI labels and social publishing
have their own pages:

.. toctree::
   :titlesonly:

   NrLlm
   Worker
   AiLabelAndPublishing

.. _configuration-extension-settings:

Extension settings
==================

The settings live in :guilabel:`Admin Tools > Settings > Extension
Configuration > nr_repurpose`:

.. list-table::
   :header-rows: 1
   :widths: 30 70

   * - Setting
     - Documented in
   * - ``technicalBeUserUid``
     - :confval:`technicalBeUserUid <technicalbeuseruid>` below
   * - ``aiLabelImages``, ``aiLabelTexts``
     - :ref:`configuration-ai-label`
   * - ``socialWebhookUrl``, ``socialWebhookSecret``
     - :ref:`configuration-social`

.. confval:: technicalBeUserUid
   :name: technicalbeuseruid
   :type: int
   :default: 0

   The uid of a backend user the generation job runs as while it reads
   secrets from nr-vault.

   A job runs in a Symfony Messenger consumer or through the
   ``nr_repurpose:generate`` command. For both, TYPO3 boots an
   unauthenticated command-line user, so nr-vault has no actor to authorise
   and refuses every secret read. The first provider call then fails, and the
   job stops in the analysis step before any artifact exists.

   With a uid above ``0``, the whole job runs inside nr-vault's
   :php:`TechnicalActorContextInterface::runAs()` for that user. nr-vault
   then checks the secrets against this user like against any backend user:
   an administrator may read every secret (unless nr-vault runs the
   ``hardened`` security profile with ``disableAdminOverride`` set), any other
   user needs access to the provider key's secret through its owner or its
   groups. nr-vault refuses a
   uid without a backend user record, a disabled user, a user outside its
   start and end time, and a user not stored at root level (``pid`` 0) before
   the job starts; the worker then marks the job failed with nr-vault's
   message.

   With ``0`` (the default) the job runs without an actor, as before this
   setting existed.

   The technical user does not need the ``generate_audio`` and
   ``generate_vision`` permissions: those, and the nr-llm budget, are checked
   for the backend user who created the job (see
   :ref:`configuration-permissions`).

.. _configuration-permissions:

Backend capability permissions
=============================

nr_repurpose registers three ``customPermOptions`` under the ``nrrepurpose``
namespace. Two gate AI spend per group, the third the approval step:

-   ``generate_audio`` — podcast audio generation (maps to the nr-llm
    ``AUDIO`` capability).
-   ``generate_vision`` — AI imagery generation and the Vision OCR of PDF
    pages (maps to the nr-llm ``VISION`` capability).
-   ``approve_artifacts`` — approve or reject artifacts in the result view and
    schedule approved social posts (see :ref:`usage-review`). Administrators
    hold it without the option.

nr-llm has no dedicated image/speech capability, so audio generation gates on
``AUDIO`` and image/vision generation on ``VISION``.

The worker checks both options against the backend groups of the user who
created the job (an administrator holds both):

-   Without ``generate_audio`` the podcast artifact fails before any script or
    speech call, with an error naming the missing option.
-   Without ``generate_vision`` the two AI image variants of the Schaubild
    (``html_bg``, ``ki_image``) fail the same way while the plain HTML variant
    is still produced, and the story is rendered on flat backgrounds.
-   Without ``generate_vision`` a PDF is not read with Vision OCR either: in
    the ``vision`` mode, and for a scanned page in ``auto``, the page keeps its
    embedded text instead. A PDF that has no embedded text at all fails the
    job with an error naming the missing option.

The check runs before the budget check, so the denied speech, image and OCR
calls are never made, and neither is the transparent diagram render that only the
``html_bg`` variant uses. The document analysis and the text parts that stay
permitted still call the LLM as before.
