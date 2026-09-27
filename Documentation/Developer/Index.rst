.. include:: /Includes.rst.txt

.. _developer:

=========
Developer
=========

This chapter is for developers who work on the extension itself or extend it.
How a job flows through the pipeline is described in :ref:`architecture`, and
the reasons behind the main design choices in :ref:`adr`.

.. _developer-environment:

Local environment
=================

The repository ships a DDEV setup with the system binaries and a worker
container (see :ref:`installation-ddev`). Use it to run the backend module and
the worker. The tests do not run inside DDEV; they have their own runner.

.. _developer-tests:

Running the tests
=================

Every test and quality tool runs through :path:`Build/Scripts/runTests.sh`,
which starts the TYPO3 core-testing Docker images. The script in the
repository is a small bootstrap: on a fresh clone it runs
``composer install`` and then hands over to the shared runner that
``netresearch/typo3-ci-workflows`` installs as :path:`.Build/bin/runTests.sh`.

.. code-block:: bash
   :caption: Test suites and quality tools

   ./Build/Scripts/runTests.sh -s unit                  # unit tests
   ./Build/Scripts/runTests.sh -s functional            # functional tests (SQLite)
   ./Build/Scripts/runTests.sh -s functional -d mariadb # functional tests against MariaDB
   ./Build/Scripts/runTests.sh -s lint                  # PHP lint
   ./Build/Scripts/runTests.sh -p 8.3 -s cgl            # code style
   ./Build/Scripts/runTests.sh -s phpstan               # static analysis
   ./Build/Scripts/runTests.sh -s rector                # Rector dry run

The default PHP version is 8.5; ``-p`` selects another one. Run the code-style
check on PHP 8.3, because the CI code-style job uses the first PHP version of
its matrix. PHPStan runs at level 8 over :path:`Classes/`, as configured in
:path:`phpstan.neon`.

.. _developer-generator:

Adding a generator
==================

A generator produces the artifacts of one type. The orchestrator runs exactly
the services tagged ``nr_repurpose.artifact_generator``; a class that is not
tagged is never run, even when it implements the interface.

#.  Implement :php:`Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface`
    with its two methods:

    -   ``supports(GenerationContext $ctx): bool`` — whether the job asked for
        this artifact, usually by reading the job's ``want_*`` flag from
        ``$ctx->jobRow``;
    -   ``generate(GenerationContext $ctx): bool`` — inserts the generator's own
        artifact rows, fills them, and returns whether it succeeded.

    Extend :php:`AbstractGenerator` rather than starting from scratch. It
    provides the Fluid theme rendering, per-run temporary directories,
    ``failArtifact()`` and ``specializedAllowed()``, the budget and
    availability check for text-to-speech and image calls.
    :php:`AbstractTextGenerator` is the base for a structured text format, and
    :php:`AbstractDocumentGenerator` for a document printed to PDF.
    :php:`PodcastGenerator` is the reference for a generator that combines an
    LLM call with specialized calls and local rendering.

#.  Register the class in :path:`Configuration/Services.yaml` with the tag, the
    same way as the existing generators:

    .. code-block:: yaml
       :caption: Configuration/Services.yaml

       Netresearch\NrRepurpose\Generator\MyGenerator:
         public: true
         tags: ['nr_repurpose.artifact_generator']

#.  Check the capability grants before a speech or image call
    (``$ctx->grants->audio``, ``$ctx->grants->vision``, see :ref:`adr-008`),
    and call ``specializedAllowed()`` before spending on them.

#.  Name the pipeline step on every nr-llm call with a constant from
    :php:`Netresearch\NrRepurpose\Service\CallerSource`, so nr-llm's analytics
    attribute its cost (see :ref:`architecture-generation-attribution`).

One failing generator does not stop the others: record the failure on the
artifact with ``failArtifact()`` and return ``false``.

.. _developer-backends:

Another image or speech backend
===============================

The generators never call nr-llm's image or speech services directly. They use
:php:`ImageGeneratorInterface` and :php:`SpeechSynthesizerInterface`, which
:path:`Configuration/Services.yaml` aliases to :php:`DallEImageGenerator` and
:php:`OpenAiSpeechSynthesizer`. A different backend is a new adapter behind the
interface and a changed alias; the adapter still reaches the provider through
nr-llm, which holds the keys (see :ref:`adr-003`).
