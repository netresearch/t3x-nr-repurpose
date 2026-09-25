<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Generator;

use Netresearch\NrRepurpose\Generator\Support\TextLabels;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The real TextLabels against the shipped locallang.xlf: the plain-text labels follow the
 * text's language, and anything without a translation falls back to English.
 */
final class TextLabelsTest extends AbstractFunctionalTestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function labels(): array
    {
        return [
            'English question'                => ['text.faq.question', 'en', 'Q'],
            'German question'                 => ['text.faq.question', 'de', 'F'],
            'German subject'                  => ['text.newsletter.subject', 'de', 'Betreff'],
            'no translation: English'         => ['text.faq.question', 'fr', 'Q'],
            'empty language: English'         => ['text.newsletter.subject', '', 'Subject'],
            'unusable language code: English' => ['text.faq.question', 'not a locale!', 'Q'],
        ];
    }

    #[DataProvider('labels')]
    public function testLabel(string $key, string $language, string $expected): void
    {
        self::assertSame($expected, (new TextLabels($this->get(LanguageServiceFactory::class)))->get($key, $language));
    }
}
