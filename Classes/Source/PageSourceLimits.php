<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Source;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The limits of one page source (extension settings `maxSourcePages` and `maxSourceChars`).
 *
 * Every 12,000 characters of source text are one model call in the analysis, and it refuses a
 * text of more than 100 such sections (DocumentAnalyzer). HARD_MAX_CHARS stays below that, so a
 * selection the limit has cut still reaches the analysis instead of failing there.
 */
class PageSourceLimits
{
    public const int DEFAULT_MAX_PAGES = 25;

    public const int DEFAULT_MAX_CHARS = 200000;

    public const int HARD_MAX_CHARS = 1000000;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function maxPages(): int
    {
        return $this->setting('maxSourcePages', self::DEFAULT_MAX_PAGES);
    }

    public function maxChars(): int
    {
        return min($this->setting('maxSourceChars', self::DEFAULT_MAX_CHARS), self::HARD_MAX_CHARS);
    }

    private function setting(string $name, int $default): int
    {
        try {
            $value = $this->extensionConfiguration->get('nr_repurpose', $name);
        } catch (Throwable) {
            // Not configured at all (an installation from before the setting existed).
            return $default;
        }

        $number = is_numeric($value) ? (int) $value : 0;

        return $number > 0 ? $number : $default;
    }
}
