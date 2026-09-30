<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Netresearch\NrRepurpose\Ingestion\BoundedResponseReader;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\QueuedHttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class BoundedResponseReaderTest extends TestCase
{
    /**
     * A client whose transport fails the way a real handler does. MockHandler would
     * also hand the queued exception to on_headers, which no real handler does.
     */
    private function failingClient(Throwable $reason): Client
    {
        return new Client(['handler' => HandlerStack::create(static fn (): PromiseInterface => Create::rejectionFor($reason))]);
    }

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

    public function testTheRequestOptionsBoundTheTransferInsideTheHandler(): void
    {
        $options = BoundedResponseReader::requestOptions(100, 5.0, 'https://example.com/');

        self::assertSame(5.0, $options[RequestOptions::TIMEOUT]);
        self::assertGreaterThan(0, $options[RequestOptions::CONNECT_TIMEOUT]);
        self::assertFalse($options[RequestOptions::ALLOW_REDIRECTS]);
        // Not streamed: with ext-curl the whole transfer, headers included, then runs under
        // one total timeout and curl's header size cap.
        self::assertArrayNotHasKey(RequestOptions::STREAM, $options);
        // Not decoded by the handler: the progress callback counts the bytes on the wire,
        // so a decoded body is only bounded when read() inflates it.
        self::assertFalse($options[RequestOptions::DECODE_CONTENT]);
        self::assertIsCallable($options[RequestOptions::ON_HEADERS]);
        self::assertIsCallable($options[RequestOptions::PROGRESS]);
    }

    public function testTheProgressCallbackStopsOneByteAboveTheLimit(): void
    {
        $progress = BoundedResponseReader::requestOptions(100, 60.0, 'https://example.com/')[RequestOptions::PROGRESS];
        $progress(0, 100, 0, 0);

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379464);

        $progress(0, 101, 0, 0);
    }

    public function testTheProgressCallbackStopsAfterTheTimeout(): void
    {
        $progress = BoundedResponseReader::requestOptions(100, 0.0, 'https://example.com/')[RequestOptions::PROGRESS];
        usleep(1000);

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379465);

        $progress(0, 1, 0, 0);
    }

    public function testTheHeaderCallbackRefusesADeclaredLengthAboveTheLimit(): void
    {
        $onHeaders = BoundedResponseReader::requestOptions(100, 5.0, 'https://example.com/')[RequestOptions::ON_HEADERS];
        $onHeaders(new Response(200, ['Content-Length' => '100']));

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379464);

        $onHeaders(new Response(200, ['Content-Length' => '101']));
    }

    public function testSendRethrowsTheIngestionExceptionAHandlerWrapped(): void
    {
        // The stream and mock handlers wrap an exception from on_headers or progress
        // in a RequestException; the fetchers must see the size code, not "not reachable".
        $http = new QueuedHttpClient(new Response(200, ['Content-Length' => '101'], 'short'));

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379464);

        BoundedResponseReader::send($http->client, new Request('GET', 'https://example.com/'), 100, 5.0, 'https://example.com/');
    }

    public function testSendTurnsACurlTimeoutIntoTheTimeoutCode(): void
    {
        $request = new Request('GET', 'https://example.com/');
        // What the installed Guzzle raises for curl's errno 28: Guzzle 8 a
        // ConnectTimeoutException, Guzzle 7 a ConnectException carrying the errno.
        $timeout = class_exists(ConnectTimeoutException::class)
            ? new ConnectTimeoutException('cURL error 28: Operation timed out', $request)
            : new ConnectException('cURL error 28: Operation timed out', $request, null, ['errno' => 28]);
        self::assertInstanceOf(ConnectException::class, $timeout);
        $client = $this->failingClient($timeout);

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379465);

        BoundedResponseReader::send($client, $request, 100, 5.0, 'https://example.com/');
    }

    /**
     * Guzzle 8 raises a stall before the response head as NetworkTimeoutException,
     * which is neither a ConnectException nor a RequestException.
     */
    public function testSendTurnsAGuzzle8NetworkTimeoutIntoTheTimeoutCode(): void
    {
        if (!class_exists(NetworkTimeoutException::class)) {
            self::markTestSkipped('Guzzle 8 only');
        }

        $request = new Request('GET', 'https://example.com/');
        $timeout = new NetworkTimeoutException('cURL error 28: Operation timed out', $request);

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379465);

        BoundedResponseReader::send($this->failingClient($timeout), $request, 100, 5.0, 'https://example.com/');
    }

    public function testSendLeavesOtherTransportErrorsToTheCaller(): void
    {
        $request = new Request('GET', 'https://example.com/');
        $refused = new ConnectException('cURL error 7: Connection refused', $request, null, ['errno' => 7]);

        try {
            BoundedResponseReader::send($this->failingClient($refused), $request, 100, 5.0, 'https://example.com/');
            self::fail('A refused connection must reach the caller');
        } catch (ConnectException $e) {
            self::assertSame($refused, $e);
        }
    }

    public function testInflatesAGzipBody(): void
    {
        $response = new Response(200, ['Content-Encoding' => 'gzip'], (string) gzencode('<p>hello</p>'));

        self::assertSame('<p>hello</p>', BoundedResponseReader::read($response, 100, 5.0, 'https://example.com/'));
    }

    public function testAGzipBodyIsLimitedByItsInflatedSize(): void
    {
        // 10 MB of one letter is ~10 KB on the wire: the limit applies to what is inflated.
        $bomb = (string) gzencode(str_repeat('a', 10_000_000));
        self::assertLessThan(100_000, strlen($bomb));

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379464);

        BoundedResponseReader::read(new Response(200, ['Content-Encoding' => 'gzip'], $bomb), 100_000, 5.0, 'https://example.com/');
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
