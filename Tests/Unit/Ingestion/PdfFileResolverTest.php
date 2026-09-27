<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use GuzzleHttp\Psr7\HttpFactory;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\RemoteSourceGuard;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StaticHostResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final class PdfFileResolverTest extends TestCase
{
    public function testRefusesAPdfUrlOnThePrivateNetworkBeforeSendingAnyRequest(): void
    {
        $client = new class implements ClientInterface {
            public int $calls = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->calls;

                return (new HttpFactory())->createResponse(200);
            }
        };
        $resolver = new PdfFileResolver(
            $this->createStub(ResourceFactory::class),
            $client,
            new HttpFactory(),
            new RemoteSourceGuard(new StaticHostResolver(['intranet.example' => ['10.0.0.8']])),
        );

        try {
            $resolver->resolve(['source_type' => 'pdf_url', 'source_value' => 'https://intranet.example/report.pdf']);
            self::fail('A private address must be refused');
        } catch (IngestionException $e) {
            self::assertSame(1749379463, $e->getCode());
        }

        self::assertSame(0, $client->calls);
    }
}
