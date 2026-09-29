<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Fractor migrates the non-PHP files Rector does not read: Fluid templates,
 * TypoScript, YAML, XML/XLIFF and .htaccess. Same set level as Build/rector.php.
 */

use a9f\Fractor\Configuration\FractorConfiguration;
use a9f\Fractor\ValueObject\Indent;
use a9f\FractorXliff\Configuration\XliffProcessorOption;
use a9f\FractorXml\Configuration\XmlProcessorOption;
use a9f\Typo3Fractor\Set\Typo3LevelSetList;

return FractorConfiguration::configure()
    ->withPaths([
        __DIR__ . '/../Configuration/',
        __DIR__ . '/../Resources/',
    ])
    ->withSets([
        Typo3LevelSetList::UP_TO_TYPO3_14,
    ])
    // TYPO3 v14 XLIFF files use two-space indentation; the processors default
    // to four.
    ->withOptions([
        XliffProcessorOption::INDENT_CHARACTER => Indent::STYLE_SPACE,
        XliffProcessorOption::INDENT_SIZE      => 2,
        XmlProcessorOption::INDENT_CHARACTER   => Indent::STYLE_SPACE,
        XmlProcessorOption::INDENT_SIZE        => 2,
    ])
    ->withSkip([
        // Installed by npm for the Playwright renderer, not part of the extension.
        __DIR__ . '/../Resources/Private/NodeRenderer/node_modules/',
        // Fractor 1.1's XLIFF processor re-serialises every file and reports
        // any difference from its own layout as a change (CodeFormatRule):
        // one <source> per line, attributes reordered. No XLIFF migration rule
        // of the TYPO3 14 set applies to these files; the only finding is that
        // layout, which is a formatting choice and not a migration.
        __DIR__ . '/../Resources/Private/Language/',
    ]);
