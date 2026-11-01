.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-010:

==================================
ADR-010: Page and File Job Sources
==================================

:Status: Accepted
:Date: 2026-11-01
:Authors: Netresearch DTT GmbH

.. _adr-010-context:

Context
=======

The *New job* form offered a free-text source field. A page of the same
installation could only be entered as a URL, and a *PDF file (FAL)* job needed
its file attached afterwards in the record view, so the form alone produced a
job that failed.

The form now picks a page, optionally with its subpages, or a file. That raises
four questions: which picker, how a page becomes source text, what a page tree
may hold, and how many files a job takes.

.. _adr-010-decision:

Decision
========

**The pickers are TYPO3's element browser**, the page tree and file tree editors
know from FormEngine, shown in the backend modal. The form opens the route
``wizard_element_browser`` with ``useEvents=1``, so a pick arrives as the event
``typo3:element-browser:message`` on the modal's iframe. FormEngine listens to
the same event; the module form has no FormEngine and needs none of it. The
protocol was read at v14.3.0, the lowest version the extension allows, and at
v14.3.10, the latest. The file browser comes from EXT:filelist, which moved from
``require-dev`` to ``require``.

**A page is read through its public URL**, by the :php:`WebPageFetcher` every URL
job uses. The URL comes from the page's site, in the default language. So the
text is what a visitor reads, plugin output included, a change to the text
extraction applies to both kinds of job, and nothing a visitor may not read
reaches the language model.

The rejected alternatives:

-   Reading ``tt_content`` directly. It needs no HTTP, but it misses everything
    a plugin renders, repeats what the frontend already does, and would send
    unpublished content to the provider.
-   Rendering the page inside the worker. TYPO3 has no public API for a
    frontend request from the command line.

Editors do not type these URLs: the site configuration and the page's slug build
them. :php:`RemoteSourceGuard::createOwnSiteRequest()` therefore does not apply
the blocked address ranges, because a site on an intranet or a container network
is a normal installation. Every URL an editor enters still goes through
:php:`RemoteSourceGuard::createRequest()`.

A site with ``base: /`` names no host: it answers on whatever host a request
uses, and the worker has no request. The form stores the page's public URL with
the job (``source_url``), built with the scheme and host of the request that
created it, and the worker takes them from there. That host comes from a request,
not from the site configuration, so these pages go through the guard for editor
URLs, blocked ranges included. The same column gives a social post of a page job
its source URL.

**Which pages a tree selects** follows from reading as a visitor.
:php:`PageTreeWalker` walks the default language of the live workspace level by
level, each level in the order of the page tree:

.. list-table::
   :header-rows: 1
   :widths: 40 30 30

   * - Page
     - The page
     - Its subpages
   * - The job's owner has no access (web mount, *show page*)
     - left out
     - left out
   * - Hidden, or outside start and end time
     - left out
     - stay, unless *extend to subpages* is set
   * - Restricted to frontend groups
     - left out
     - stay, unless *extend to subpages* is set
   * - Link, shortcut, backend user section, mount point, spacer, folder, or a
       doktype from 200 on
     - left out
     - stay

A selection without any page a visitor can open, or a page without a site, is
refused in the form. The form cannot see everything the worker meets, so a page
can still fail to load; the job then reads the others and reports it, and fails
only when no page gave text.

**A page source has limits**, as extension settings: ``maxSourcePages`` (default
25) and ``maxSourceChars`` (default 200,000, at most 1,000,000). Every 12,000
characters are one model call in the analysis, and the analysis refuses more than
100 such sections, so a cut selection must stay below that or it would only fail
later. The page limit stops the walk, which is why the nearest pages stay. The
text limit ends the selection at a page boundary; only a first page longer than
the limit is itself cut. The form warns when the tree is over the page limit. The
result view lists what was left out, from a report in its own column
(``source_report``), because a successful run clears ``error_message``.

**Access is checked twice**, in the form for the requesting user and in the
worker for the job's owner: mounts, permissions and the tree can change while a
job waits. A page needs a web mount and *show page*, a file a file mount and
``readFile``. The worker reads the owner's records through :php:`SourceAccess`
and does not configure the storage: :php:`ResourceStorage` objects are shared
within the process, and a file mount added for one owner would stay for the next
job. The form also calls :php:`File::checkActionPermission()`, which answers for
the logged-in user.

**One job takes one file.** ``source_pdf`` allows one item, the resolver reads
the first reference, and pages, ``pdf_mode`` and the page limit all describe one
document. Several files would need a merged document model. A picked file
is stored as a normal ``sys_file_reference`` of the job, so existing ``pdf_fal``
jobs and new ones take the same path. Only PDF files can be picked: the pipeline
reads nothing else.

.. _adr-010-consequences:

Consequences
============

●  An editor starts a job from the page tree, without copying a URL, and a
   ``pdf_fal`` job no longer needs the record view.

●  The source type column keeps its old values; ``page`` is one more. Jobs of
   the other types run as before. A ``pdf_fal`` job without an owner (one created
   in the record view) has no one to check against and keeps resolving its file.

◐  A page needs a public URL the worker can reach. A site behind HTTP
   authentication, or a page that redirects, counts as not readable; the job says
   how many. With ``base: /`` the host of the backend must also serve the
   frontend, and must not resolve to a private address.

◐  The label and ``source_url`` are what they were when the job was made: a page
   renamed or moved later keeps its old label and link on the job, while the worker
   builds current URLs from the site.

◐  A draft cannot be repurposed: a hidden page and a workspace version are not
   read. Only the default language is read.

◐  The worker's file check repeats the conditions of
   :php:`ResourceStorage::checkFileActionPermission()` that depend on the user. A
   new condition in core has to be added here. The owner's file mounts stay in
   the runtime cache of the worker process until it is recycled.

◐  A long tree takes up to 30 seconds per page in the worker, one page after
   the other. The limits bound the total.

✕  Several files in one job remain unsupported.
