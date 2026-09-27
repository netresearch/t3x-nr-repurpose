<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use GuzzleHttp\Psr7\HttpFactory;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\RemoteSourceGuard;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StaticHostResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class WebPageFetcherTest extends TestCase
{
    private function client(int $status, string $body): ClientInterface
    {
        $factory  = new HttpFactory();
        $response = $factory->createResponse($status)
            ->withBody($factory->createStream($body));

        return new class ($response) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
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

    public function testThrowsIngestionExceptionOnNon2xx(): void
    {
        $fetcher = new WebPageFetcher($this->client(404, 'Not found'), new HttpFactory(), StaticHostResolver::publicGuard());

        $this->expectException(IngestionException::class);
        $fetcher->fetch('https://example.com/missing');
    }

    public function testRefusesAnInternalAddressBeforeSendingAnyRequest(): void
    {
        $client = new class implements ClientInterface {
            public int $calls = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->calls;

                return (new HttpFactory())->createResponse(200);
            }
        };
        $fetcher = new WebPageFetcher($client, new HttpFactory(), new RemoteSourceGuard(new StaticHostResolver()));

        try {
            $fetcher->fetch('http://169.254.169.254/latest/meta-data/');
            self::fail('The metadata endpoint must be refused');
        } catch (IngestionException $e) {
            self::assertSame(1749379463, $e->getCode());
        }

        self::assertSame(0, $client->calls);
    }

    public function testThrowsIngestionExceptionOnEmptyBody(): void
    {
        $fetcher = new WebPageFetcher($this->client(200, '   '), new HttpFactory(), StaticHostResolver::publicGuard());

        $this->expectException(IngestionException::class);
        $fetcher->fetch('https://example.com/empty');
    }
}
