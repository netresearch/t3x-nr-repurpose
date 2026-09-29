<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Fluid rendering of the branded theme templates below
 * Resources/Private/Templates/Generated/<area>/ via the v14 ViewFactory API, for the
 * generators that render HTML. The using class gets the view factory injected through
 * its constructor and hands it out via viewFactory().
 */
trait RendersThemeTemplates
{
    abstract protected function viewFactory(): ViewFactoryInterface;

    /**
     * Render one of the branded theme templates to an HTML string.
     *
     * @param array<string, mixed> $variables
     */
    protected function renderTemplate(string $area, string $theme, array $variables): string
    {
        $templateName = $theme === 'nr' ? 'Nr' : 'Neutral';
        $view         = $this->viewFactory()->create(new ViewFactoryData(
            templatePathAndFilename: GeneralUtility::getFileAbsFileName(
                sprintf('EXT:nr_repurpose/Resources/Private/Templates/Generated/%s/%s.html', $area, $templateName),
            ),
        ));
        $view->assignMultiple($variables);

        return $view->render();
    }
}
