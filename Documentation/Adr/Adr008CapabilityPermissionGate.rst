.. include:: /Includes.rst.txt

.. _adr-008:

====================================================
ADR-008: Capability Permissions Checked Before Spend
====================================================

:Status: Accepted
:Date: 2026-09-23
:Authors: Netresearch DTT GmbH

.. _adr-008-context:

Context
=======

Speech synthesis and image generation are the expensive calls of a run. Since
0.1.0 the extension has registered two ``customPermOptions`` in
:path:`ext_localconf.php`, ``nrrepurpose:generate_audio`` and
``nrrepurpose:generate_vision``, and documented them as gating this spend per
backend group. Their labels name the nr-llm capabilities they correspond to:
``AUDIO`` for speech and ``VISION`` for imagery, because nr-llm has no
dedicated image or speech capability.

Nothing checked the two options. The extension kept a public alias for
nr-llm's capability permission service, to be used "once capability gating is
added"; nr-llm 0.26 withdrew that service (nr-llm ADR-117), and 0.4.0 removed
the alias. Every editor could therefore generate podcast audio and AI imagery,
whatever the group settings said.

The generation runs in a worker on the command line. There,
``$GLOBALS['BE_USER']`` is not the editor who created the job; with
:confval:`technicalBeUserUid <technicalbeuseruid>` set it is the technical
actor of the nr-vault wrapper.

.. _adr-008-decision:

Decision
========

**The extension checks the two options itself.**
:php:`CapabilityGrantResolver` loads the backend user stored on the job
(``be_user``) into a fresh :php:`BackendUserAuthentication`, fetches its groups,
and asks ``check('custom_options', …)`` for each option, the way the backend
answers it. An administrator holds both; a missing, deleted or disabled user
holds neither.

**Once per run, before the generators.** The orchestrator resolves the grants
once and hands them to every generator in :php:`GenerationContext` as a
:php:`CapabilityGrants` value. The context's default is no grant.

**Before the budget check.** A generator checks the grant before it asks
nr-llm's :php:`BudgetService` and before any call:

-   without ``generate_audio`` the podcast artifact fails before the script
    and the speech calls, with a message naming the missing option;
-   without ``generate_vision`` the Schaubild's two AI image variants
    (``html_bg``, ``ki_image``) fail the same way, the plain ``html`` variant
    is still produced, and the story is rendered on flat backgrounds.

The third option in the namespace, ``approve_artifacts``, is a different
mechanism: it gates the approval step in the result view (see
:ref:`adr-007`), not generation.

.. _adr-008-consequences:

Consequences
============

●  A denied speech or image call is never made, so it costs nothing and
   touches no budget.

●  The check reads the job's creator, not whoever runs the worker, so the
   technical actor for nr-vault needs neither option.

●  Group permissions are read the way the backend reads them, including
   subgroups and administrator rights.

◐  Editors whose groups do not carry the options lost these artifacts with
   0.5.2; an administrator has to grant them in the backend group's custom
   module options.

◐  The grants are resolved when the run starts; a group change during a
   running job takes effect with the next run.

✕  The mapping to nr-llm's ``AUDIO`` and ``VISION`` capabilities is a label:
   nr-llm does not enforce these options itself.
