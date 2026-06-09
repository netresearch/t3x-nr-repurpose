.. include:: /Includes.rst.txt

.. _start:

=============
nr_repurpose
=============

:Extension key:
   nr_repurpose

:Package name:
   :composer:`netresearch/nr-repurpose`

:Version:
   |release|

:Language:
   en

:Author:
   Netresearch DTT GmbH

:License:
   This document is published under the
   `CC BY 4.0 <https://creativecommons.org/licenses/by/4.0/>`__ license.

:Rendered:
   |today|

----

Turn a webpage (URL) or PDF into three AI-generated media artifacts — a
two-host **podcast**, a **diagram** (Schaubild) in three variants, and a 9:16
**Instagram story** — straight from the TYPO3 backend. Built on
:composer:`netresearch/nr-llm` for AI access and :composer:`netresearch/nr-vault`
for key storage.

----

Getting started
===============

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: 📘 Introduction

      Learn what nr_repurpose is, which three artifacts it
      produces, and how it builds on the nr-llm / nr-vault
      foundation.

      .. card-footer:: :ref:`Read more <introduction>`
         :button-style: btn btn-secondary stretched-link

   .. card:: 📦 Installation

      Install via Composer, meet the runtime requirements
      (poppler, ffmpeg, chromium, a worker), and seed the
      OpenAI key into nr-vault.

      .. card-footer:: :ref:`Read more <installation>`
         :button-style: btn btn-primary stretched-link

   .. card:: 🔧 Configuration

      Wire the nr-llm provider, route the generation message
      to the async transport, and tune the extension's own
      options.

      .. card-footer:: :ref:`Read more <configuration>`
         :button-style: btn btn-secondary stretched-link

   .. card:: 🎬 Usage

      Walk through the *Content Studio* backend module and
      the ``nr_repurpose:generate`` CLI command.

      .. card-footer:: :ref:`Read more <usage>`
         :button-style: btn btn-primary stretched-link

   .. card:: 🏗️ Architecture

      How a job flows: ingest → analyze → generate (async
      queue + worker) → render (chromium) → store in FAL.

      .. card-footer:: :ref:`Read more <architecture>`
         :button-style: btn btn-secondary stretched-link

   .. card:: 📐 Decision records

      The architectural decisions behind the async pipeline,
      the Node renderer, and the vault-backed key.

      .. card-footer:: :ref:`Read more <adr>`
         :button-style: btn btn-secondary stretched-link

----

.. card-grid::
   :columns: 1
   :gap: 4
   :card-height: 100

   .. card:: [n] A Netresearch extension

      Professional TYPO3 development, AI integration,
      and enterprise consulting since 2002.

      .. card-footer:: `netresearch.de <https://www.netresearch.de>`__
         :button-style: btn btn-secondary stretched-link

----

**Table of contents**

.. toctree::
   :maxdepth: 2
   :titlesonly:

   Introduction/Index
   Installation/Index
   Configuration/Index
   Usage/Index
   Architecture/Index
   Adr/Index
   Changelog

.. Meta Menu

.. toctree::
   :hidden:

   Sitemap
