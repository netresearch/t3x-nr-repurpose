<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\RemoteSourceGuard;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StaticHostResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\AbstractLogger;
use Stringable;

final class RemoteSourceGuardTest extends TestCase
{
    public function testAllowsAPublicHostOverHttpsAndHttp(): void
    {
        $resolver = new StaticHostResolver(['example.com' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']]);
        $guard    = new RemoteSourceGuard($resolver);

        $guard->assertAllowed(new Uri('https://example.com/report.pdf'));
        $guard->assertAllowed(new Uri('http://example.com/'));

        self::assertSame(['example.com', 'example.com'], $resolver->asked);
    }

    public function testAllowsAPublicIpLiteralWithoutResolving(): void
    {
        $resolver = new StaticHostResolver();

        (new RemoteSourceGuard($resolver))->assertAllowed(new Uri('https://93.184.215.14/'));

        self::assertSame([], $resolver->asked);
    }

    /** @return iterable<string, array{string}> */
    public static function blockedSchemes(): iterable
    {
        yield 'file' => ['file:///etc/passwd'];
        yield 'ftp' => ['ftp://example.com/x.pdf'];
        yield 'gopher' => ['gopher://example.com/'];
        yield 'none' => ['//example.com/x'];
    }

    #[DataProvider('blockedSchemes')]
    public function testRefusesEverySchemeButHttpAndHttps(string $url): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379460);

        (new RemoteSourceGuard(new StaticHostResolver(['example.com' => ['93.184.215.14']])))->assertAllowed(new Uri($url));
    }

    /** @return iterable<string, array{string}> */
    public static function blockedAddresses(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1'];
        yield 'IPv4 loopback, not .1' => ['127.10.20.30'];
        yield 'RFC 1918 10/8' => ['10.1.2.3'];
        yield 'RFC 1918 172.16/12 low' => ['172.16.0.1'];
        yield 'RFC 1918 172.16/12 high' => ['172.31.255.254'];
        yield 'RFC 1918 192.168/16' => ['192.168.1.1'];
        yield 'CGNAT 100.64/10' => ['100.64.0.1'];
        yield 'link-local metadata' => ['169.254.169.254'];
        yield 'IPv4 unspecified' => ['0.0.0.0'];
        yield 'IPv4 multicast' => ['224.0.0.1'];
        yield 'IPv4 broadcast' => ['255.255.255.255'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'IPv6 unspecified' => ['::'];
        yield 'IPv6 unique local fc00' => ['fc00::1'];
        yield 'IPv6 unique local fd' => ['fd12:3456:789a::1'];
        yield 'IPv6 link-local' => ['fe80::1'];
        yield 'IPv6 multicast' => ['ff02::1'];
        yield 'IPv4-mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'IPv4-mapped metadata' => ['::ffff:169.254.169.254'];
        yield 'IPv4-compatible loopback' => ['::127.0.0.1'];
        yield 'IPv4-mapped loopback, hex' => ['::ffff:7f00:1'];
        yield 'IPv4-mapped metadata, hex' => ['::ffff:a9fe:a9fe'];
        yield 'IPv4-compatible loopback, hex' => ['::7f00:1'];
        yield 'NAT64 well-known prefix, loopback' => ['64:ff9b::7f00:1'];
        yield 'NAT64 well-known prefix, metadata' => ['64:ff9b::a9fe:a9fe'];
        yield '6to4 loopback' => ['2002:7f00:1::'];
        yield '6to4 RFC 1918' => ['2002:a00:5::1'];
    }

    #[DataProvider('blockedAddresses')]
    public function testRefusesAHostResolvingToABlockedAddress(string $address): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379463);

        (new RemoteSourceGuard(new StaticHostResolver(['internal.example' => [$address]])))
            ->assertAllowed(new Uri('https://internal.example/page'));
    }

    /**
     * The URL goes through createRequest(), as in the fetchers. guzzlehttp/psr7 2.9.0 (the
     * lowest dependency set) cannot parse an IPv6 literal with a dotted IPv4 tail; there the
     * refusal is the unparseable-URL one, with a newer release the address check's.
     */
    #[DataProvider('blockedAddresses')]
    public function testRefusesABlockedIpLiteral(string $address): void
    {
        $url = 'https://' . (str_contains($address, ':') ? '[' . $address . ']' : $address) . '/';

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode($this->guzzleParses($url) ? 1749379463 : 1749379468);

        (new RemoteSourceGuard(new StaticHostResolver()))->createRequest(new HttpFactory(), 'GET', $url);
    }

    public function testCreatesTheRequestForAnAllowedUrl(): void
    {
        $guard = new RemoteSourceGuard(new StaticHostResolver(['example.com' => ['93.184.215.14']]));

        $guarded = $guard->createRequest(new HttpFactory(), 'GET', 'https://example.com/report.pdf');

        self::assertSame('GET', $guarded->request->getMethod());
        self::assertSame('https://example.com/report.pdf', (string) $guarded->request->getUri());
    }

    public function testTheRequestCarriesEveryAddressTheHostWasCheckedWith(): void
    {
        $guard = new RemoteSourceGuard(new StaticHostResolver(['example.com' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']]));

        $guarded = $guard->createRequest(new HttpFactory(), 'GET', 'https://example.com/report.pdf');

        self::assertSame(['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'], $guarded->addresses);
    }

    public function testRefusesAUrlTheRequestFactoryCannotParse(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array<mixed>> */
            public array $contexts = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };
        $resolver = new StaticHostResolver();
        $factory  = $this->createStub(RequestFactoryInterface::class);
        $factory->method('createRequest')->willThrowException(new InvalidArgumentException('Unable to parse URI: detail'));

        try {
            (new RemoteSourceGuard($resolver, $logger))->createRequest($factory, 'GET', 'https://user:secret@bad.example/?token=abc');
            self::fail('An unparseable URL must be refused');
        } catch (IngestionException $e) {
            self::assertSame(1749379468, $e->getCode());
            self::assertSame('Source URL cannot be parsed: https://bad.example/', $e->getMessage());
            self::assertNull($e->getPrevious(), 'the library exception repeats the raw URL');
        }

        // The log gets the URL without user name, password and query too.
        self::assertSame([['url' => 'https://bad.example/']], $logger->contexts);
        self::assertSame([], $resolver->asked);
    }

    public function testRefusesAUrlGuzzleCannotParse(): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379468);

        (new RemoteSourceGuard(new StaticHostResolver()))->createRequest(new HttpFactory(), 'GET', 'https://:80');
    }

    private function guzzleParses(string $url): bool
    {
        try {
            return (string) new Uri($url) !== '';
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @return iterable<string, array{string}> */
    public static function numericIpv4Spellings(): iterable
    {
        yield 'one decimal number' => ['2130706433'];
        yield 'zero' => ['0'];
        yield 'octal parts' => ['0177.0.0.1'];
        yield 'octal metadata' => ['0251.0376.0251.0376'];
        yield 'hex part' => ['0x7f.0.0.1'];
        yield 'one hex number' => ['0X7F000001'];
        yield 'two parts' => ['127.1'];
        yield 'one octal number' => ['017700000001'];
    }

    /**
     * The HTTP client reads these spellings as an IPv4 address (Guzzle folds them to a
     * dotted quad itself), while the platform resolver may read them differently or pass
     * them to DNS. Even a public answer for the spelling must not let it through.
     */
    #[DataProvider('numericIpv4Spellings')]
    public function testRefusesANumericIpv4SpellingWithoutResolvingIt(string $host): void
    {
        $resolver = new StaticHostResolver([strtolower($host) => ['93.184.215.14'], $host => ['93.184.215.14']]);

        try {
            (new RemoteSourceGuard($resolver))->assertAllowed(new Uri('https://' . $host . '/'));
            self::fail('A numeric IPv4 spelling must be refused');
        } catch (IngestionException $e) {
            self::assertSame(1749379467, $e->getCode());
        }

        self::assertSame([], $resolver->asked);
    }

    public function testResolvesANameWithNumericLabels(): void
    {
        $resolver = new StaticHostResolver(['10.example' => ['93.184.215.14'], '1.2.3.4.5' => ['93.184.215.14']]);
        $guard    = new RemoteSourceGuard($resolver);

        $guard->assertAllowed(new Uri('https://10.example/'));
        $guard->assertAllowed(new Uri('https://1.2.3.4.5/'));

        self::assertSame(['10.example', '1.2.3.4.5'], $resolver->asked);
    }

    public function testRefusesWhenAnyResolvedAddressIsBlocked(): void
    {
        $guard = new RemoteSourceGuard(new StaticHostResolver(['mixed.example' => ['93.184.215.14', '10.0.0.5']]));

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379463);

        $guard->assertAllowed(new Uri('https://mixed.example/'));
    }

    public function testAllowsNeighboursOfTheBlockedRanges(): void
    {
        $guard = new RemoteSourceGuard(new StaticHostResolver([
            'a.example' => ['172.15.255.255'],
            'b.example' => ['172.32.0.1'],
            'c.example' => ['100.128.0.1'],
            'd.example' => ['169.255.0.1'],
            'e.example' => ['2001:db8::1'],
            // Public IPv4 93.184.215.14 embedded in NAT64 (as DNS64 synthesizes it) and in 6to4.
            'f.example' => ['64:ff9b::5db8:d70e'],
            'g.example' => ['2002:5db8:d70e::1'],
        ]));

        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $host) {
            $guard->assertAllowed(new Uri('https://' . $host . '.example/'));
        }

        $this->addToAssertionCount(7);
    }

    public function testRefusesAHostThatDoesNotResolve(): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379462);

        (new RemoteSourceGuard(new StaticHostResolver()))->assertAllowed(new Uri('https://nowhere.invalid/'));
    }

    public function testRefusesAUrlWithoutHost(): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379461);

        // Guzzle's Uri turns an empty http(s) host into "localhost" (judged by the
        // resolver like any name); other UriInterface implementations keep it empty.
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('');

        (new RemoteSourceGuard(new StaticHostResolver()))->assertAllowed($uri);
    }

    public function testTheRefusalDoesNotDiscloseTheResolvedAddressButLogsIt(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array<mixed>> */
            public array $contexts = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };
        $guard = new RemoteSourceGuard(new StaticHostResolver(['wiki.corp.example' => ['10.20.30.40']]), $logger);

        try {
            $guard->assertAllowed(new Uri('https://wiki.corp.example/'));
            self::fail('A private address must be refused');
        } catch (IngestionException $e) {
            self::assertSame(1749379463, $e->getCode());
            self::assertStringNotContainsString('10.20.30.40', $e->getMessage());
            self::assertStringContainsString('wiki.corp.example', $e->getMessage());
        }

        self::assertSame([['host' => 'wiki.corp.example', 'address' => '10.20.30.40']], $logger->contexts);
    }
}
