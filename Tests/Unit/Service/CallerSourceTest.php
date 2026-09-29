<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Service;

use Netresearch\NrRepurpose\Service\CallerSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * nr-llm's Analytics module groups usage and cost by the two strings on each
 * telemetry row. A wrong extension key files every call under a name no one
 * looks for; two steps sharing one operation value merge into one line.
 */
#[CoversClass(CallerSource::class)]
final class CallerSourceTest extends TestCase
{
    public function testExtensionMatchesTheComposerExtensionKey(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);
        self::assertIsArray($composer);

        self::assertSame(
            $composer['extra']['typo3/cms']['extension-key'] ?? null,
            CallerSource::EXTENSION,
        );
    }

    public function testEveryOperationHasItsOwnValue(): void
    {
        $operations = (new ReflectionClass(CallerSource::class))->getConstants();
        unset($operations['EXTENSION']);

        self::assertNotEmpty($operations);
        self::assertSame(
            [],
            array_keys(array_filter(array_count_values($operations), static fn (int $count): bool => $count > 1)),
            'Each pipeline step needs a distinct operation value, or nr-llm merges them in its breakdown.',
        );
    }
}
