<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Privatization\Rector\Property\PrivatizeFinalClassPropertyRector;

$configure = require_once __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: paths, code-quality sets, rule skips,
    // and the package's ergebnis-free phpstan-rector.neon.
    $configure($rectorConfig, __DIR__ . '/..');

    // paths() REPLACES rather than merges, so the shared default list is
    // restated here with Tests/ appended — the test suite is part of what CI
    // judges and must not drift from the rules applied to Classes/.
    $rectorConfig->paths([
        __DIR__ . '/../Classes',
        __DIR__ . '/../Configuration',
        __DIR__ . '/../Resources',
        __DIR__ . '/../Tests',
        __DIR__ . '/../ext_localconf.php',
    ]);

    $rectorConfig->skip([
        // The Extbase DataMapper assigns mapped properties through
        // AbstractDomainObject::_setProperty(), i.e. from the parent class
        // scope. A `private` declaration there fails with "Cannot access
        // private property" on every hydration, so the domain models keep
        // their `protected` properties even once the classes become final.
        PrivatizeFinalClassPropertyRector::class => [
            __DIR__ . '/../Classes/Domain/Model',
        ],
    ]);
};
