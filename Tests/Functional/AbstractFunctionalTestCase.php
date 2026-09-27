<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base for all nr_repurpose functional tests.
 *
 * Loads the full dependency chain (nr_repurpose depends on nr_llm, which depends on
 * nr_vault) so the testing-framework PackageCollection can resolve the dependency graph.
 * Subclasses add their own fixtures/imports.
 *
 * EXT:install is loaded because composer.json requires typo3/cms-install. Since
 * composer.json declares extra.typo3/cms.version and Package.providesPackages
 * (TYPO3 #108345), TYPO3 builds the dependency graph from composer.json's
 * `require` instead of ext_emconf.php's `depends`, so every required core
 * extension must be present or the instance does not boot.
 */
abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-repurpose',
    ];
}
