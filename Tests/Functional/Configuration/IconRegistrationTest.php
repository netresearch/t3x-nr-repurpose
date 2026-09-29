<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Configuration;

use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Imaging\IconRegistry;

/**
 * The icons as TYPO3 registers them: the extension icon is the [n] symbol file, and the
 * backend module keeps its own feature glyph (netresearch-branding,
 * references/typo3-extension-branding.md "Module Icon": the [n] logo is not reused for a
 * feature module). The SVG content is checked in Tests/Unit/Configuration/IconsTest.
 */
final class IconRegistrationTest extends AbstractFunctionalTestCase
{
    public function testTheModuleKeepsItsFeatureIconAndTheExtensionIconIsTheSymbolFile(): void
    {
        $icons  = $this->get(IconRegistry::class);
        $module = $this->get(ModuleProvider::class)->getModule('web_nrrepurpose');

        self::assertInstanceOf(ModuleInterface::class, $module);
        self::assertSame('tx-nrrepurpose-module', $module->getIconIdentifier());
        self::assertSame('EXT:nr_repurpose/Resources/Public/Icons/module.svg', $icons->getIconConfigurationByIdentifier('tx-nrrepurpose-module')['options']['source'] ?? null);
        self::assertSame('EXT:nr_repurpose/Resources/Public/Icons/Extension.svg', $icons->getIconConfigurationByIdentifier('nr_repurpose')['options']['source'] ?? null);

        $withExtensionIcon = array_filter(
            $this->get(ModuleProvider::class)->getModules(grouped: false),
            static fn (ModuleInterface $candidate): bool => $candidate->getIconIdentifier() === 'nr_repurpose',
        );
        self::assertSame([], array_keys($withExtensionIcon), 'no backend module shows the extension icon');
    }
}
