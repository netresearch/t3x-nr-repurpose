<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Provenance;

use Netresearch\NrRepurpose\Domain\ValueObject\AiLabelSettings;
use Netresearch\NrRepurpose\Generator\Support\TextLabels;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Builds the AiLabelSettings of a run from the extension settings `aiLabelImages`
 * (default on) and `aiLabelTexts` (default off), the installed extension version and
 * the label texts in the language of the generated artifacts (ADR-005).
 *
 * Not final: unit tests replace the version lookup.
 */
class AiLabelSettingsFactory
{
    public const EXTENSION_KEY = 'nr_repurpose';

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly TextLabels $labels,
    ) {}

    public function create(string $language): AiLabelSettings
    {
        return new AiLabelSettings(
            generator: $this->generator(),
            imageLabel: $this->enabled('aiLabelImages', true) ? $this->labels->get('aiLabel.image', $language) : null,
            textLine: $this->enabled('aiLabelTexts', false) ? $this->labels->get('aiLabel.text', $language) : null,
        );
    }

    /** "nr_repurpose <version>", or just the key when the package reports no version. */
    private function generator(): string
    {
        try {
            $version = $this->extensionVersion();
        } catch (Throwable) {
            // Package not active or its metadata unreadable: the key alone still names the tool.
            $version = '';
        }

        return $version === '' ? AiLabelSettings::GENERATOR : AiLabelSettings::GENERATOR . ' ' . $version;
    }

    /** The installed version, as TYPO3's package manager reports it. */
    protected function extensionVersion(): string
    {
        return ExtensionManagementUtility::getExtensionVersion(self::EXTENSION_KEY);
    }

    /**
     * A setting that is missing — an installation whose extension configuration was not
     * re-synchronised since the setting was added — keeps its documented default.
     */
    private function enabled(string $setting, bool $default): bool
    {
        try {
            $value = $this->extensionConfiguration->get(self::EXTENSION_KEY, $setting);
        } catch (Throwable) {
            return $default;
        }

        return is_scalar($value) ? (bool) $value : $default;
    }
}
