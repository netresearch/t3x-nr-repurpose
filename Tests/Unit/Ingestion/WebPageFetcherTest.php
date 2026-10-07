<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Netresearch\NrRepurpose\Ingestion\HostResolverInterface;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\RemoteSourceGuard;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\QueuedHttpClient;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StaticHostResolver;
use PHPUnit\Framework\TestCase;

final class WebPageFetcherTest extends TestCase
{
    private function client(int $status, string $body): ClientInterface
    {
        return QueuedHttpClient::answering($status, $body)->client;
    }

    public function testExtractsTitleAndMainContentDroppingBoilerplate(): void
    {
        $html    = (string) file_get_contents(__DIR__ . '/../../Fixtures/Web/article.html');
        $fetcher = new WebPageFetcher($this->client(200, $html), new HttpFactory(), StaticHostResolver::publicGuard());

        $doc = $fetcher->fetch('https://example.com/q1');

        self::assertSame('Quarterly Results 2026', $doc->title);
        self::assertStringContainsString('Revenue grew by 12 percent', $doc->text);
        self::assertStringContainsString('dividend of 1.20 euro', $doc->text);
        self::assertStringNotContainsString('tracking', $doc->text);
        self::assertStringNotContainsString('Home About Contact', $doc->text);
        self::assertStringNotContainsString('newsletter', $doc->text);
        self::assertStringNotContainsString('All rights reserved', $doc->text);
        self::assertSame(0, $doc->pageCount);
        self::assertSame('static', $doc->meta['fetchedVia']);
        self::assertSame('https://example.com/q1', $doc->sourceLabel);
        self::assertSame('en', $doc->languageHint);
    }

    /**
     * The label goes into the text model's prompts and onto the published story, slide
     * deck and handout; the fetch itself needs the URL as entered.
     */
    public function testTheLabelIsTheUrlWithoutCredentialsQueryOrFragment(): void
    {
        $http = QueuedHttpClient::answering(200, (string) file_get_contents(__DIR__ . '/../../Fixtures/Web/article.html'));

        $doc = (new WebPageFetcher($http->client, new HttpFactory(), StaticHostResolver::publicGuard()))
            ->fetch('https://user:secret@example.com/q1?token=abc#frag');

        self::assertSame('https://example.com/q1', $doc->sourceLabel);
        self::assertSame('user:secret', $http->handler->getLastRequest()?->getUri()->getUserInfo(), 'the request keeps the credentials');
        self::assertSame('token=abc', $http->handler->getLastRequest()?->getUri()->getQuery(), 'the request keeps the query');
    }

    public function testThrowsIngestionExceptionOnNon2xx(): void
    {
        $fetcher = new WebPageFetcher($this->client(404, 'Not found'), new HttpFactory(), StaticHostResolver::publicGuard());

        $this->expectException(IngestionException::class);
        $fetcher->fetch('https://example.com/missing');
    }

    public function testRefusesAnInternalAddressBeforeSendingAnyRequest(): void
    {
        $http    = QueuedHttpClient::answering(200, '<html><body>secret</body></html>');
        $fetcher = new WebPageFetcher($http->client, new HttpFactory(), new RemoteSourceGuard(new StaticHostResolver()));

        try {
            $fetcher->fetch('http://169.254.169.254/latest/meta-data/');
            self::fail('The metadata endpoint must be refused');
        } catch (IngestionException $e) {
            self::assertSame(1749379463, $e->getCode());
        }

        self::assertSame(1, $http->unconsumed());
    }

    /** guzzlehttp/psr7 2.9.0 cannot parse this literal; a newer release parses it and the address check refuses it. */
    public function testRefusesAnIpv4MappedLiteralBeforeSendingAnyRequest(): void
    {
        $http    = QueuedHttpClient::answering(200, '<html><body>secret</body></html>');
        $fetcher = new WebPageFetcher($http->client, new HttpFactory(), new RemoteSourceGuard(new StaticHostResolver()));

        try {
            $fetcher->fetch('https://[::ffff:169.254.169.254]/latest/meta-data/');
            self::fail('An IPv4-mapped metadata literal must be refused');
        } catch (IngestionException $e) {
            self::assertContains($e->getCode(), [1749379463, 1749379468]);
        }

        self::assertSame(1, $http->unconsumed());
    }

    /**
     * The host is looked up once, by the guard; the transfer connects to the address
     * that lookup returned, whatever the name resolves to afterwards.
     */
    public function testConnectsToTheAddressTheGuardChecked(): void
    {
        $resolver = new class implements HostResolverInterface {
            public int $lookups = 0;

            public function resolve(string $host): array
            {
                return ++$this->lookups === 1 ? ['93.184.215.14'] : ['127.0.0.1'];
            }
        };
        $http = QueuedHttpClient::answering(200, '<html><body><p>Text</p></body></html>');

        (new WebPageFetcher($http->client, new HttpFactory(), new RemoteSourceGuard($resolver)))->fetch('https://example.com/page');

        self::assertSame(1, $resolver->lookups);
        self::assertSame(
            [CURLOPT_RESOLVE => ['example.com:443:93.184.215.14']],
            $http->handler->getLastOptions()[RequestOptions::CURL] ?? null,
        );
    }

    public function testThrowsIngestionExceptionOnEmptyBody(): void
    {
        $fetcher = new WebPageFetcher($this->client(200, '   '), new HttpFactory(), StaticHostResolver::publicGuard());

        $this->expectException(IngestionException::class);
        $fetcher->fetch('https://example.com/empty');
    }

    public function testSendsTheTransferLimitsAndDoesNotFollowRedirects(): void
    {
        $http = QueuedHttpClient::answering(200, '<html><body><p>Text</p></body></html>');

        (new WebPageFetcher($http->client, new HttpFactory(), StaticHostResolver::publicGuard()))->fetch('https://example.com/');

        $options = $http->handler->getLastOptions();
        self::assertSame(WebPageFetcher::TIMEOUT_SECONDS, $options[RequestOptions::TIMEOUT]);
        self::assertGreaterThan(0, $options[RequestOptions::CONNECT_TIMEOUT]);
        self::assertFalse($options[RequestOptions::ALLOW_REDIRECTS]);
        self::assertFalse($options[RequestOptions::DECODE_CONTENT]);
        self::assertIsCallable($options[RequestOptions::PROGRESS]);
    }

    public function testARedirectToTheMetadataEndpointIsNotFollowed(): void
    {
        $http = new QueuedHttpClient(
            new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            new Response(200, [], '<html><body>instance secret</body></html>'),
        );

        try {
            (new WebPageFetcher($http->client, new HttpFactory(), StaticHostResolver::publicGuard()))->fetch('https://example.com/');
            self::fail('A redirect must not be followed');
        } catch (IngestionException $e) {
            self::assertSame(1749379411, $e->getCode());
        }

        self::assertSame(1, $http->unconsumed());
    }

    public function testRefusesAPageLargerThanTheLimit(): void
    {
        $body = '<html><body><p>' . str_repeat('a', WebPageFetcher::MAX_BYTES) . '</p></body></html>';

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379464);

        (new WebPageFetcher($this->client(200, $body), new HttpFactory(), StaticHostResolver::publicGuard()))->fetch('https://example.com/huge');
    }

    public function testRefusesADeclaredContentLengthAboveTheLimitBeforeReading(): void
    {
        $http = new QueuedHttpClient(new Response(200, ['Content-Length' => (string) (WebPageFetcher::MAX_BYTES + 1)], 'short'));

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379464);

        (new WebPageFetcher($http->client, new HttpFactory(), StaticHostResolver::publicGuard()))->fetch('https://example.com/huge');
    }

    public function testAcceptsAPageOfExactlyTheLimit(): void
    {
        $prefix = '<html><body><p>';
        $suffix = '</p></body></html>';
        $body   = $prefix . str_repeat('a', WebPageFetcher::MAX_BYTES - strlen($prefix) - strlen($suffix)) . $suffix;

        $doc = (new WebPageFetcher($this->client(200, $body), new HttpFactory(), StaticHostResolver::publicGuard()))->fetch('https://example.com/big');

        self::assertSame(WebPageFetcher::MAX_BYTES - strlen($prefix) - strlen($suffix), strlen($doc->text));
    }
}
