<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base for all nr_repurpose functional tests.
 *
 * Loads the full dependency chain (nr_repurpose depends on nr_llm, which depends on
 * nr_vault) so the testing-framework PackageCollection can resolve the dependency graph.
 * Subclasses add their own fixtures/imports.
 */
abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-repurpose',
    ];
}
