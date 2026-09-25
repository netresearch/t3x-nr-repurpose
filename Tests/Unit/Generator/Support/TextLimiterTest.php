<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Support;

use InvalidArgumentException;
use Netresearch\NrRepurpose\Generator\Support\TextCut;
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
            'newline is a word boundary'                 => ["alpha beta\ngammadelta", 15, 'alpha beta…'],
            'no-break space is a word boundary'          => ["alpha beta\u{00A0}gammadelta", 15, 'alpha beta…'],
            'sentence end before a no-break space'       => ["First sentence here.\u{00A0}Then more words", 25, 'First sentence here.'],
        ];
    }

    #[DataProvider('cases')]
    public function testTruncate(string $text, int $max, string $expected): void
    {
        $result = (new TextLimiter())->truncate($text, $max);

        self::assertSame($expected, $result);
        self::assertLessThanOrEqual($max, mb_strlen($result));
    }

    /**
     * The review probe: a 294-character X post whose only sentence end inside 280 is the
     * opening "Big news!". Cutting there kept 9 characters and was stored as done; the
     * post must keep its substance instead, cut at a word boundary.
     */
    public function testAnEarlySentenceEndDoesNotShrinkThePostToItsOpening(): void
    {
        $post = 'Big news! Our revenue grew by twelve percent in the third quarter, driven by '
            . str_repeat('strong demand in every region and ', 6) . 'rising so far';
        self::assertSame(294, mb_strlen($post));

        $cut = (new TextLimiter())->cut($post, 280);

        self::assertSame(TextCut::WORD, $cut->mode);
        self::assertStringStartsWith('Big news! Our revenue grew by twelve percent', $cut->text);
        self::assertStringEndsWith('every region…', $cut->text);
        self::assertGreaterThanOrEqual(140, mb_strlen($cut->text));
        self::assertLessThanOrEqual(280, mb_strlen($cut->text));
    }

    public function testASentenceCutKeepingHalfTheLimitIsKept(): void
    {
        // "One. Two." is 9 of 18 characters — exactly half — so the sentence cut stands.
        $cut = (new TextLimiter())->cut('One. Two. Three four five six seven', 18);

        self::assertSame(TextCut::SENTENCE, $cut->mode);
        self::assertSame('One. Two.', $cut->text);
    }

    public function testTheCutModeIsReported(): void
    {
        $limiter = new TextLimiter();

        self::assertSame(TextCut::NONE, $limiter->cut('Fits.', 10)->mode);
        self::assertFalse($limiter->cut('Fits.', 10)->wasCut());
        self::assertSame(TextCut::SENTENCE, $limiter->cut('One. Two. Three.', 12)->mode);
        self::assertSame(TextCut::WORD, $limiter->cut('alpha beta gamma delta', 13)->mode);
        self::assertTrue($limiter->cut('alpha beta gamma delta', 13)->wasCut());
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
