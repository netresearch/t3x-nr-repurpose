<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\Privatization\Rector\Property\PrivatizeFinalClassPropertyRector;

$configure = require_once __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: paths (Classes/, Configuration/, Resources/,
    // ext_*.php and, with `tests`, Tests/), code-quality sets, rule skips,
    // the package's ergebnis-free phpstan-rector.neon, and the PHP and
    // TYPO3 level sets for the lowest supported versions (composer.json:
    // php ^8.3, typo3/cms-core ^14.3).
    $configure($rectorConfig, __DIR__ . '/..', [
        'tests' => true,
        'php'   => '8.3',
        'typo3' => 14,
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
        // TEMPORARY: typed class constants (PHP 8.3) are applied everywhere
        // except in files that open pull requests are changing at the time
        // this set was raised, to keep those branches free of conflicts.
        // Remove this entry once they are merged and apply the rule there.
        // Classes/Ingestion is skipped as a directory because a pull request
        // adds new files with constants there.
        AddTypeToConstRector::class => [
            __DIR__ . '/../Classes/Generator/Image/DallEImageGenerator.php',
            __DIR__ . '/../Classes/Generator/SchaubildGenerator.php',
            __DIR__ . '/../Classes/Generator/Speech/OpenAiSpeechSynthesizer.php',
            __DIR__ . '/../Classes/Ingestion',
            __DIR__ . '/../Classes/Social/WebhookSocialPublisher.php',
        ],
    ]);
};
