<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IngestionExceptionTest extends TestCase
{
    public function testIsRuntimeExceptionAndCarriesCodeAndPrevious(): void
    {
        $previous = new RuntimeException('boom');
        $e        = new IngestionException('source unreachable', 1749379400, $previous);

        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertSame('source unreachable', $e->getMessage());
        self::assertSame(1749379400, $e->getCode());
        self::assertSame($previous, $e->getPrevious());
    }
}
