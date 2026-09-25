.. include:: /Includes.rst.txt

.. _adr:
.. _architecture-decision-records:

==============================
Architecture Decision Records
==============================

This section documents the significant architectural decisions made while
building nr_repurpose. Each record captures the context, the decision, and its
consequences, in the format used across the Netresearch TYPO3 extensions.

.. _adr-decision-records:

Decision records
================

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-001: Asynchronous generation via Symfony Messenger

      Why job generation runs in a worker, and why the
      handler swallows failures onto the job row.

      .. card-footer:: :ref:`Read <adr-001>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-002: Node + Playwright renderer for image composition

      Why HTML-to-PNG and AI-background compositing run
      through a bundled Node renderer driving system Chromium.

      .. card-footer:: :ref:`Read <adr-002>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-003: Provider credentials delegated to nr-llm

      Why this extension owns no provider code and no API key,
      and reaches every provider only through nr-llm.

      .. card-footer:: :ref:`Read <adr-003>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-004: Text formats as schema-validated structured output

      Why the text formats ask nr-llm for schema-validated JSON and
      enforce limits in the generator, not only in the prompt.

      .. card-footer:: :ref:`Read <adr-004>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-005: AI label on every artifact, embedded at storage

      Why every file carries a machine-readable AI marker written in
      PHP when it is stored, and which visible labels are optional.

      .. card-footer:: :ref:`Read <adr-005>`
         :button-style: btn btn-secondary stretched-link

.. toctree::
   :hidden:

   Adr001AsyncGenerationViaSymfonyMessenger
   Adr002NodePlaywrightRenderer
   Adr003ProviderCredentialsViaNrLlm
   Adr004TextFormatsAsStructuredOutput
   Adr005AiGeneratedContentLabel
