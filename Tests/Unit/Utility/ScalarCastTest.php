<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Utility;

use Netresearch\NrRepurpose\Utility\ScalarCast;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

final class ScalarCastTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function intCases(): iterable
    {
        yield 'int' => [42, 42];
        yield 'numeric string' => ['17', 17];
        yield 'leading digits' => ['3abc', 3];
        yield 'non-numeric string' => ['abc', 0];
        yield 'empty string' => ['', 0];
        yield 'float' => [2.9, 2];
        yield 'true' => [true, 1];
        yield 'false' => [false, 0];
        yield 'null' => [null, 0];
        yield 'non-empty array' => [[1], 0];
        yield 'object' => [new stdClass(), 0];
    }

    #[DataProvider('intCases')]
    public function testIntCastsScalarsAsTheOperatorDoesAndMapsTheRestToZero(mixed $value, int $expected): void
    {
        self::assertSame($expected, ScalarCast::int($value));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function stringCases(): iterable
    {
        yield 'string' => ['text', 'text'];
        yield 'int' => [7, '7'];
        yield 'float' => [1.5, '1.5'];
        yield 'true' => [true, '1'];
        yield 'false' => [false, ''];
        yield 'null' => [null, ''];
        yield 'stringable' => [new class implements Stringable {
            public function __toString(): string
            {
                return 'from object';
            }
        }, 'from object'];
        yield 'array' => [['a'], ''];
        yield 'plain object' => [new stdClass(), ''];
    }

    #[DataProvider('stringCases')]
    public function testStringCastsScalarsAndStringablesAndMapsTheRestToEmpty(mixed $value, string $expected): void
    {
        self::assertSame($expected, ScalarCast::string($value));
    }
}
