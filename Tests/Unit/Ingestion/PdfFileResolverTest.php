<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\RemoteSourceGuard;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\QueuedHttpClient;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StaticHostResolver;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final class PdfFileResolverTest extends TestCase
{
    private function resolver(QueuedHttpClient $http, ?RemoteSourceGuard $guard = null): PdfFileResolver
    {
        return new PdfFileResolver(
            $this->createStub(ResourceFactory::class),
            $http->client,
            new HttpFactory(),
            $guard ?? StaticHostResolver::publicGuard(),
        );
    }

    public function testRefusesAPdfUrlOnThePrivateNetworkBeforeSendingAnyRequest(): void
    {
        $http     = QueuedHttpClient::answering(200, '%PDF-1.7');
        $resolver = $this->resolver($http, new RemoteSourceGuard(new StaticHostResolver(['intranet.example' => ['10.0.0.8']])));

        try {
            $resolver->resolve(['source_type' => 'pdf_url', 'source_value' => 'https://intranet.example/report.pdf']);
            self::fail('A private address must be refused');
        } catch (IngestionException $e) {
            self::assertSame(1749379463, $e->getCode());
        }

        self::assertSame(1, $http->unconsumed());
    }

    public function testDownloadsWithATimeoutAndWithoutFollowingRedirects(): void
    {
        $http = QueuedHttpClient::answering(200, '%PDF-1.7 body');

        $path = $this->resolver($http)->resolve(['source_type' => 'pdf_url', 'source_value' => 'https://example.com/r.pdf']);
        $body = (string) file_get_contents($path);
        unlink($path);

        self::assertSame('%PDF-1.7 body', $body);
        $options = $http->handler->getLastOptions();
        self::assertSame(PdfFileResolver::TIMEOUT_SECONDS, $options[RequestOptions::TIMEOUT]);
        self::assertFalse($options[RequestOptions::ALLOW_REDIRECTS]);
        self::assertFalse($options[RequestOptions::DECODE_CONTENT]);
        self::assertIsCallable($options[RequestOptions::PROGRESS]);
    }

    public function testRefusesAPdfLargerThanTheLimit(): void
    {
        $http = new QueuedHttpClient(new Response(200, [], str_repeat('x', PdfFileResolver::MAX_BYTES + 1)));

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379464);

        $this->resolver($http)->resolve(['source_type' => 'pdf_url', 'source_value' => 'https://example.com/huge.pdf']);
    }
}
