<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Netresearch\NrRepurpose\Generator\Support\TextLabels;

/**
 * TextLabels without TYPO3's localization stack: the English and German plain-text labels
 * the extension ships, English for any other language — the same fallback the real
 * implementation has (its own functional test pins that against locallang.xlf).
 */
final class MapTextLabels extends TextLabels
{
    private const LABELS = [
        'en' => [
            'text.faq.question'         => 'Q',
            'text.faq.answer'           => 'A',
            'text.newsletter.subject'   => 'Subject',
            'text.newsletter.preheader' => 'Preheader',
        ],
        'de' => [
            'text.faq.question'         => 'F',
            'text.faq.answer'           => 'A',
            'text.newsletter.subject'   => 'Betreff',
            'text.newsletter.preheader' => 'Preheader',
        ],
    ];

    /** @var list<array{key: string, language: string}> */
    public array $calls = [];

    public function __construct()
    {
        // Intentionally empty: no LanguageServiceFactory in a unit test.
    }

    public function get(string $key, string $language): string
    {
        $this->calls[] = ['key' => $key, 'language' => $language];

        return self::LABELS[$language][$key] ?? self::LABELS['en'][$key];
    }
}
