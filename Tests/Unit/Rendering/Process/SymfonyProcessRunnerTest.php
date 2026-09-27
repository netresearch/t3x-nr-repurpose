<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering\Process;

use Netresearch\NrRepurpose\Rendering\Process\SymfonyProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyProcessRunner::class)]
final class SymfonyProcessRunnerTest extends TestCase
{
    public function testThePassedEnvironmentReachesTheChildAndTheInheritedOneStays(): void
    {
        $result = (new SymfonyProcessRunner())->run(
            ['sh', '-c', 'printf "%s|%s" "$NR_REPURPOSE_TEST_VAR" "${PATH:+path-set}"'],
            null,
            10.0,
            ['NR_REPURPOSE_TEST_VAR' => '/opt/chromium'],
        );

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame('/opt/chromium|path-set', $result->stdout);
    }
}
