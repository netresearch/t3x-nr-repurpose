.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-012:

=====================================
ADR-012: A Page Job Names Its Site
=====================================

:Status: Accepted
:Date: 2026-11-02
:Authors: Netresearch DTT GmbH

.. _adr-012-context:

Context
=======

From version 0.43.4 on, nr-llm lets a site name the LLM configuration its AI
calls run on, in the site setting ``nrllm.configuration`` (nr-llm ADR-244). It
applies to a call that states its site with ``withSite()`` on its options.
nr-llm does not take the site from the request, because a worker has none.
Every generation of this extension runs in the worker.

.. _adr-012-decision:

Decision
========

**A page job takes its site from the page it reads.** When the form assigns a
page source, :php:`JobSourceAssigner` stores the identifier of the site that
the stored public URL (``source_url``) comes from in the column ``site``. That
is the site of the first page the job reads. The worker reads the column
through :php:`JobSnapshot`. Nothing has to be looked up again in the worker,
and a retry runs for the same site.

**Other jobs name no site.** An external URL, a PDF URL and a PDF file belong
to no site of the installation. The page selected in the module's page tree
says nothing about the source either. It is part of the request, and nr-llm
rejects a site guessed from the request for the same reason. Such jobs, and
jobs stored before the column existed, keep the instance default.

**Every text call of the run names the site.** :php:`JobLlmOptions` adds it to
the options of the analysis (synthesis, retry and every map step), the podcast
script, the diagram body, the story copy and every text and document format.

**What the site changes is nr-llm's decision.**

-   The configuration ``nr_repurpose_text`` is the extension's own named
    configuration. nr-llm treats it as an explicit choice that wins over the
    site. While it exists, is active and is not restricted to backend groups,
    a call runs on it, and the site only selects the term lists bound to the
    site.
-   Without that record, :php:`ConfiguredCompletionService` takes the instance
    default. For a call that names a site, nr-llm puts the site's
    configuration in place of the default.
-   A site that names a configuration that does not exist, is disabled or is
    restricted to backend groups makes the call fail. It does not fall back.
    The worker has no user to check the backend groups against.

**Image, speech and the OCR of PDF pages do not name the site.** Of the nr-llm
0.43.4 services this extension calls, only ``chat()``, ``complete()`` and
``streamChat()`` read the site. The image and speech services take their configuration by identifier
(``nr_repurpose_image``, ``nr_repurpose_tts``), and vision keeps the instance
default. A site on those options would change nothing.

The site API exists from nr-llm 0.43.4 on, so the requirement rises to
``^0.43.4``.

.. _adr-012-consequences:

Consequences
============

●  An installation with several sites runs a page job on the configuration of
   the page's site, without a configuration per job.

◐  While ``nr_repurpose_text`` can be used, which the extension's setup
   recommends, the site's configuration does not apply to the text calls. To
   use it, an administrator marks another configuration as the instance default
   (one with a directly assigned model and no backend-group restriction, the
   only kind nr-llm uses as the default) and then deletes or deactivates that
   record; removing the instance default
   would fail every job without a site configuration.

◐  A running worker keeps what it read: changes to ``nr_repurpose_text`` and to
   a site's ``nrllm.configuration`` reach it after it restarts.

◐  A tree whose pages belong to several sites runs on the site of the first
   page it reads.

◐  Without ``nr_repurpose_text``, a site that names a configuration that cannot
   be used fails the job at the analysis, its first text call.

✕  A URL or PDF job cannot be run on a site's configuration.
