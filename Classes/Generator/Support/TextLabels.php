<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Labels inside a generated plain text ("Q:"/"A:", "Subject:"), in the language the text
 * is written in — the detected source language, the same one the prompt asks for — not
 * the backend user's. Read from the extension's locallang.xlf; a language without a
 * translation, or a code TYPO3 does not know, gets the English label.
 *
 * Not final: unit tests replace it with a fixed map.
 */
class TextLabels
{
    private const FILE = 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:';

    public function __construct(private readonly LanguageServiceFactory $languageServiceFactory) {}

    public function get(string $key, string $language): string
    {
        // TYPO3 answers a language without a translation file — or an empty or unknown
        // code, which the LLM-detected language can be — with the English source label.
        return $this->languageServiceFactory->create($language)->sL(self::FILE . $key);
    }
}
