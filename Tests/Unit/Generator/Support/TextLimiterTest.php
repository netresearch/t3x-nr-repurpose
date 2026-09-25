<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Support;

use InvalidArgumentException;
use Netresearch\NrRepurpose\Generator\Support\TextLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextLimiterTest extends TestCase
{
    /** @return array<string, array{0: string, 1: int, 2: string}> */
    public static function cases(): array
    {
        return [
            'fits unchanged'                             => ['One. Two.', 9, 'One. Two.'],
            'surrounding whitespace trimmed'             => ["  One.  \n", 4, 'One.'],
            'cut after the last whole sentence'          => ['One. Two. Three.', 12, 'One. Two.'],
            'sentence end exactly at the limit'          => ['One. Two. Three.', 9, 'One. Two.'],
            'question and exclamation marks'             => ['Why? Because! And more text here.', 20, 'Why? Because!'],
            'ellipsis character ends a sentence'         => ['Wait… then more words follow here.', 10, 'Wait…'],
            'no sentence end: word boundary + ellipsis'  => ['alpha beta gamma delta', 13, 'alpha beta…'],
            'trailing comma dropped before the ellipsis' => ['alpha, beta gamma', 9, 'alpha…'],
            'one long word: hard cut + ellipsis'         => ['abcdefghij', 5, 'abcd…'],
            'multibyte counted as characters'            => ['Größe ändert. Übermorgen mehr.', 14, 'Größe ändert.'],
            'decimal point is not a sentence end'        => ['Revenue 12.5 percent up today', 20, 'Revenue 12.5…'],
        ];
    }

    #[DataProvider('cases')]
    public function testTruncate(string $text, int $max, string $expected): void
    {
        $result = (new TextLimiter())->truncate($text, $max);

        self::assertSame($expected, $result);
        self::assertLessThanOrEqual($max, mb_strlen($result));
    }

    public function testResultNeverExceedsTheLimitForAnyCut(): void
    {
        $limiter = new TextLimiter();
        $text    = 'Short. A somewhat longer sentence follows! Then a question? Finally ein Schluss mit Umlauten: äöü.';
        for ($max = 2, $len = mb_strlen($text) + 3; $max <= $len; ++$max) {
            self::assertLessThanOrEqual($max, mb_strlen($limiter->truncate($text, $max)), 'limit ' . $max);
        }
    }

    public function testRejectsALimitBelowTwo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TextLimiter())->truncate('text', 1);
    }
}
