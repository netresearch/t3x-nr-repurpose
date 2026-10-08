.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-009:

====================================
ADR-009: Data Class of the Chat Tool
====================================

:Status: Accepted
:Date: 2026-10-08
:Authors: Netresearch DTT GmbH

.. _adr-009-context:

Context
=======

nr-llm decides per run which tools a model is offered. One axis of that gate
is the trust zone (nr-llm ADR-094): every tool has a data class, the sensitivity
of what it returns into the run, and every provider has a trust zone, which
caps the data class a run against it may collect. A provider without a zone
counts as ``externalGlobal``, whose ceiling is ``editorContent``. With
``tools.dataClassEnforcement = enforce``, the setting nr-llm ships, a tool above
the ceiling is not offered.

A tool states its class by implementing nr-llm's
:php:`ToolDataClassInterface`; otherwise its group's default applies, and a
group nr-llm does not know resolves to ``secretAdjacent``, the strictest class.
:php:`StartRepurposeJobTool` declared nothing, and its group ``nr_repurpose``
is not one of nr-llm's groups. The tool therefore ranked as secret-adjacent,
and a run against a provider in the default zone never saw it, even with the
tool and the group switched on.

What the tool returns is small and fixed: on success, the uid of the job row it
created, the source URL without query and fragment, and the artifact names; on
a refusal, a fixed message. The URL and the artifact names are the caller's own
arguments. The content of the source is not returned; the worker fetches and
processes it later, outside the run.

.. _adr-009-decision:

Decision
========

**The tool declares** ``editorContent``. The job is an unpublished backend
record, and the result refers to it. nr-llm's ``editing`` group is classified
the same way, for the same reason: a writing tool echoes back what it just
set.

**Not** ``publicContent``. The result names an internal record that nobody
outside the backend can read.

**Not higher.** ``sourceCode`` and the classes above it describe the
installation's code, configuration, diagnostics or credentials. The tool
returns none of them.

The class is a property of the code: an administrator cannot relabel it, as
nr-llm's interface intends.

.. _adr-009-consequences:

Consequences
============

●  With the tool and the group switched on, and unless a skill's tool list
   leaves it out, a run against a provider in any trust zone is offered the
   tool; every call still waits for a person's approval.

●  :php:`ToolDataClassInterface` exists in every nr-llm version the extension
   supports, so the declaration needs no version switch.

◐  The class covers what the tool returns into the chat run. The generation the
   job starts later sends the source's content to the providers of the
   extension's own nr-llm configurations; this gate does not see that path.

✕  If the result ever returns more, for example generated text or data from
   the source, the class has to be decided again.
