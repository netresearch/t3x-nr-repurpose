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

   .. card:: ADR-003: Vault-backed OpenAI key via nr-llm

      Why the OpenAI key lives in nr-vault and is reached
      only through nr-llm, never as plaintext here.

      .. card-footer:: :ref:`Read <adr-003>`
         :button-style: btn btn-secondary stretched-link

.. toctree::
   :hidden:

   Adr001AsyncGenerationViaSymfonyMessenger
   Adr002NodePlaywrightRenderer
   Adr003VaultBackedKeyViaNrLlm
