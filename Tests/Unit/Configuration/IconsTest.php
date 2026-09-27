<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;

/**
 * The extension icon is the Netresearch [n] symbol, the backend module keeps its own feature
 * glyph (netresearch-branding, references/typo3-extension-branding.md "Extension Icon" and
 * "Module Icon").
 */
final class IconsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** The frame and the letter of the [n] mark, from the brand reference's SVG source. */
    private const FRAME_PATH = 'M209.6,0V31.62h32.77a26.38,26.38,0,0,1,26.44,26.43V242';

    private const LETTER_PATH = 'M221.44,120.41c0-34.48-13.94-57.82-48.93-57.82';

    public function testTheExtensionIconIsTheNetresearchSymbolInTheBrandColours(): void
    {
        $svg = (string) file_get_contents(self::ROOT . '/Resources/Public/Icons/Extension.svg');

        self::assertStringContainsString('<title>Netresearch DTT GmbH</title>', $svg);
        self::assertMatchesRegularExpression('~<path fill="#2F99A4" d="' . preg_quote(self::FRAME_PATH, '~') . '~', $svg);
        self::assertMatchesRegularExpression('~<path fill="#585961" d="' . preg_quote(self::LETTER_PATH, '~') . '~', $svg);
        // No accent orange, no white glyph and no inline style (backend CSP, style-src-attr).
        self::assertStringNotContainsString('#FF4D00', $svg);
        self::assertStringNotContainsString('#FFFFFF', $svg);
        self::assertStringNotContainsString('style', $svg);
    }

    public function testTheModuleKeepsItsFeatureIcon(): void
    {
        /** @var array<string, array{source: string}> $icons */
        $icons = require self::ROOT . '/Configuration/Icons.php';
        /** @var array<string, array{iconIdentifier?: string}> $modules */
        $modules = require self::ROOT . '/Configuration/Backend/Modules.php';

        $identifiers = array_filter(array_column($modules, 'iconIdentifier'));
        self::assertSame(['tx-nrrepurpose-module'], array_values($identifiers));
        self::assertSame('EXT:nr_repurpose/Resources/Public/Icons/module.svg', $icons['tx-nrrepurpose-module']['source']);
        self::assertSame('EXT:nr_repurpose/Resources/Public/Icons/Extension.svg', $icons['nr_repurpose']['source']);
    }
}
