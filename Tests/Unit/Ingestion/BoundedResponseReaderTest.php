<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Netresearch\NrRepurpose\Ingestion\BoundedResponseReader;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BoundedResponseReaderTest extends TestCase
{
    public function testReturnsABodyWithinTheLimits(): void
    {
        $body = str_repeat('0123456789', 3000);

        self::assertSame($body, BoundedResponseReader::read(new Response(200, [], $body), strlen($body), 5.0, 'https://example.com/'));
    }

    public function testRefusesOneByteAboveTheLimit(): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379464);

        BoundedResponseReader::read(new Response(200, [], str_repeat('x', 101)), 100, 5.0, 'https://example.com/');
    }

    public function testAReadThatReturnsNothingBeforeTheEndIsATimeout(): void
    {
        // What a stream handler read looks like when its idle timeout expires.
        $stalled = FnStream::decorate(Utils::streamFor('never read'), [
            'read' => static fn (int $length): string => '',
            'eof'  => static fn (): bool => false,
        ]);

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379465);

        BoundedResponseReader::read(new Response(200, [], $stalled), 100, 5.0, 'https://example.com/');
    }

    public function testStopsWhenTheWholeTransferTakesLongerThanTheTimeout(): void
    {
        // A body that keeps arriving: without the overall deadline this would only
        // end at the size limit (and fail with the size code instead).
        $trickle = FnStream::decorate(Utils::streamFor(''), [
            'read' => static fn (int $length): string => 'x',
            'eof'  => static fn (): bool => false,
        ]);

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379465);

        BoundedResponseReader::read(new Response(200, [], $trickle), 100_000, 0.0, 'https://example.com/');
    }

    public function testAFailedReadOnATimedOutStreamIsATimeout(): void
    {
        // Guzzle's stream handler: fread() fails once stream_set_timeout() expires.
        $expired = FnStream::decorate(Utils::streamFor('x'), [
            'read'        => static fn (int $length): string => throw new RuntimeException('Unable to read from stream'),
            'getMetadata' => static fn (?string $key = null): mixed => $key === 'timed_out' ? true : null,
        ]);

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379465);

        BoundedResponseReader::read(new Response(200, [], $expired), 100, 5.0, 'https://example.com/');
    }

    public function testAReadErrorBecomesAnIngestionException(): void
    {
        $broken = FnStream::decorate(Utils::streamFor('x'), [
            'read' => static fn (int $length): string => throw new RuntimeException('Unable to read from stream'),
        ]);

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379466);

        BoundedResponseReader::read(new Response(200, [], $broken), 100, 5.0, 'https://example.com/');
    }
}
