<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\Privatization\Rector\Property\PrivatizeFinalClassPropertyRector;
use Rector\Set\ValueObject\LevelSetList;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

$configure = require_once __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: paths, code-quality sets, rule skips,
    // and the package's ergebnis-free phpstan-rector.neon.
    $configure($rectorConfig, __DIR__ . '/..');

    // The shared config targets PHP 8.2 (phpVersion(80200), UP_TO_PHP_82);
    // this extension requires ^8.3 and TYPO3 ^14.3, so both floors are
    // raised to what composer.json allows. phpVersion() replaces the shared
    // value, sets() adds to the shared sets.
    $rectorConfig->phpVersion(80300);
    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_83,
        Typo3LevelSetList::UP_TO_TYPO3_14,
    ]);

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
