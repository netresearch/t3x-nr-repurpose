<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Netresearch\NrRepurpose\Ingestion\BoundedResponseReader;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\QueuedHttpClient;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StaticHostResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Resource\FileRepository;

/**
 * Every ingestion message that names the source URL, driven with a URL that carries a
 * user name, a password, a query token and a fragment. The job stores these messages as
 * its error_message, which every module user sees.
 */
final class SourceUrlInMessagesTest extends TestCase
{
    private const string URL = 'https://user:secret@example.com/doc.pdf?token=abc#frag';

    private const string SHOWN = 'https://example.com/doc.pdf';

    private static function fetcher(ClientInterface $client): WebPageFetcher
    {
        return new WebPageFetcher($client, new HttpFactory(), StaticHostResolver::publicGuard());
    }

    private function pdfResolver(ClientInterface $client): PdfFileResolver
    {
        return new PdfFileResolver($this->createStub(FileRepository::class), $client, new HttpFactory(), StaticHostResolver::publicGuard());
    }

    private static function answering(int $status, string $body): ClientInterface
    {
        return QueuedHttpClient::answering($status, $body)->client;
    }

    /** A transport that fails the way a real handler does (MockHandler would call on_headers with it). */
    private static function unreachable(): ClientInterface
    {
        $refused = new ConnectException('connection refused', new Request('GET', 'https://example.com/'));

        return new Client(['handler' => HandlerStack::create(static fn (): PromiseInterface => Create::rejectionFor($refused))]);
    }

    /** @return array<string, array{Closure(self): mixed, string}> the failing call, its message */
    public static function failures(): array
    {
        $pdf = static fn (self $test, ClientInterface $client): string => $test->pdfResolver($client)->resolve(['source_type' => 'pdf_url', 'source_value' => self::URL]);

        return [
            'page not reachable' => [static fn (): mixed => self::fetcher(self::unreachable())->fetch(self::URL), 'URL not reachable: ' . self::SHOWN],
            'page HTTP status'   => [static fn (): mixed => self::fetcher(self::answering(404, 'x'))->fetch(self::URL), 'URL returned HTTP 404: ' . self::SHOWN],
            'page empty body'    => [static fn (): mixed => self::fetcher(self::answering(200, ' '))->fetch(self::URL), 'URL returned an empty body: ' . self::SHOWN],
            'page without text'  => [static fn (): mixed => self::fetcher(self::answering(200, '<html><body><script>x()</script></body></html>'))->fetch(self::URL), 'No readable content extracted from: ' . self::SHOWN],
            'PDF not reachable'  => [static fn (self $test): mixed => $pdf($test, self::unreachable()), 'PDF URL not reachable: ' . self::SHOWN],
            'PDF HTTP status'    => [static fn (self $test): mixed => $pdf($test, self::answering(500, 'x')), 'PDF URL returned HTTP 500: ' . self::SHOWN],
            'PDF empty body'     => [static fn (self $test): mixed => $pdf($test, self::answering(200, '')), 'PDF URL returned an empty body: ' . self::SHOWN],
            'read failed'        => [
                static fn (): mixed => BoundedResponseReader::read(new Response(200, [], FnStream::decorate(Utils::streamFor('x'), [
                    'read' => static fn (int $length): string => throw new RuntimeException('Unable to read from stream'),
                ])), 100, 5.0, self::URL),
                'Reading the source failed: ' . self::SHOWN,
            ],
            'too large'      => [static fn (): mixed => BoundedResponseReader::read(new Response(200, [], str_repeat('x', 2 * 1024 * 1024)), 1024 * 1024, 5.0, self::URL), 'Source is larger than 1 MiB: ' . self::SHOWN],
            'timed out'      => [static fn (): mixed => BoundedResponseReader::requestOptions(100, 0.0, self::URL)[RequestOptions::PROGRESS](0, 0), 'Source did not arrive within 0 seconds: ' . self::SHOWN],
            'scheme refused' => [static fn (): mixed => StaticHostResolver::publicGuard()->createRequest(new HttpFactory(), 'GET', 'ftp://user:secret@example.com/doc.pdf?token=abc'), 'Source URL scheme "ftp" is not allowed, only http and https: ftp://example.com/doc.pdf'],
            // Guzzle's Uri fills in "localhost" for an http(s) URI without a host; TYPO3's does not.
            'no host'          => [static fn (): mixed => StaticHostResolver::publicGuard()->assertAllowed((new Uri())->withScheme('https')->withPath('/doc.pdf')->withQuery('token=abc')), 'Source URL has no host: (URL not shown)'],
            'cannot be parsed' => [static fn (): mixed => StaticHostResolver::publicGuard()->createRequest(new HttpFactory(), 'GET', 'https://user:secret@:80/?token=abc'), 'Source URL cannot be parsed: (URL not shown)'],
        ];
    }

    /** @param Closure(self): mixed $fail */
    #[DataProvider('failures')]
    public function testTheMessageNamesTheUrlWithoutCredentialsQueryOrFragment(Closure $fail, string $expected): void
    {
        try {
            $fail($this);
            self::fail('The call must fail');
        } catch (IngestionException $e) {
            self::assertSame($expected, $e->getMessage());
        }
    }
}
