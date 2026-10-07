<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use InvalidArgumentException;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SSRF guard for the editor-supplied source URL (url and pdf_url jobs). The
 * worker fetches that URL from inside the hosting network, so it must not reach
 * the host itself, the private network or a cloud metadata endpoint.
 *
 * Allowed: http and https to a host whose every resolved address is public.
 * Refused: any other scheme, a host that does not resolve, an IPv4 address
 * written in a numeric spelling other than a.b.c.d ("2130706433", "0x7f.1"),
 * and a host or IP literal with any address in loopback, RFC 1918, CGNAT, link-local (which
 * holds 169.254.169.254), unique-local IPv6, unspecified, multicast or the
 * reserved 240.0.0.0/4 block. An IPv6 address that carries an IPv4 address
 * (IPv4-mapped, IPv4-compatible, NAT64 64:ff9b::/96, 6to4) is judged as that IPv4 address.
 * A URL the request factory cannot parse is refused too (see createRequest()).
 *
 * TYPO3 core offers no equivalent for the injected client: the container builds
 * it through GuzzleClientFactory::getClient() without a context, so the
 * allowed_hosts middleware is never attached, and that middleware is a host
 * allow-list, not a private-range block.
 *
 * createRequest() returns the request together with the addresses it judged
 * (GuardedRequest), and BoundedResponseReader::send() connects to exactly those
 * addresses (unless an HTTP proxy is configured, see GuardedRequest).
 */
final readonly class RemoteSourceGuard
{
    /** @var list<string> CIDR blocks a source URL must not reach */
    private const BLOCKED_RANGES = [
        '0.0.0.0/8',       // "this network", includes the unspecified 0.0.0.0
        '10.0.0.0/8',      // RFC 1918
        '100.64.0.0/10',   // RFC 6598 carrier-grade NAT
        '127.0.0.0/8',     // loopback
        '169.254.0.0/16',  // link-local, cloud metadata 169.254.169.254
        '172.16.0.0/12',   // RFC 1918
        '192.168.0.0/16',  // RFC 1918
        '224.0.0.0/4',     // multicast
        '240.0.0.0/4',     // reserved, includes broadcast
        // :: and ::1 are judged as IPv4-compatible 0.0.0.0 and 0.0.0.1 (see embeddedIpv4()).
        'fc00::/7',        // unique local
        'fe80::/10',       // link-local
        'ff00::/8',        // multicast
    ];

    public function __construct(
        private HostResolverInterface $resolver,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Builds the request for the editor-supplied URL with the factory that sends it,
     * then judges its URI with assertAllowed(). Parsing happens here so that a URL the
     * factory rejects ends as a refusal, not as the library's exception: older
     * guzzlehttp/psr7 releases (2.9.0, the lowest the dependency tree allows) cannot
     * parse an IPv6 literal with a dotted IPv4 tail such as [::ffff:127.0.0.1].
     *
     * @throws IngestionException when the URL cannot be parsed or must not be fetched
     */
    public function createRequest(RequestFactoryInterface $requestFactory, string $method, string $url): GuardedRequest
    {
        try {
            $request = $requestFactory->createRequest($method, $url);
        } catch (InvalidArgumentException) {
            // The library's message repeats the whole URL ("Unable to parse URI: …") and
            // carries no other reason, so neither it nor the exception is kept.
            $this->logger->warning('Source URL refused: the URL cannot be parsed', [
                'url' => SourceUrlRedactor::redact($url),
            ]);

            throw new IngestionException('Source URL cannot be parsed: ' . SourceUrlRedactor::redact($url), 1749379468);
        }

        return new GuardedRequest($request, $this->assertAllowed($request->getUri()));
    }

    /**
     * @return non-empty-list<string> the addresses the host resolved to, all of them allowed
     *
     * @throws IngestionException when the URL must not be fetched
     */
    public function assertAllowed(UriInterface $uri): array
    {
        $scheme = strtolower($uri->getScheme());
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new IngestionException(
                sprintf('Source URL scheme "%s" is not allowed, only http and https: %s', $scheme, SourceUrlRedactor::redact((string) $uri)),
                1749379460,
            );
        }

        $host = trim($uri->getHost(), '[]');
        if ($host === '') {
            throw new IngestionException('Source URL has no host: ' . SourceUrlRedactor::redact((string) $uri), 1749379461);
        }

        $isIpLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$isIpLiteral && $this->isNumericIpv4Spelling($host)) {
            // "2130706433", "0x7f.1", "0177.0.0.1": the HTTP client reads these as an
            // IPv4 address, the platform resolver may read them differently (macOS
            // takes 0177 as decimal) or ask DNS, so judging its answer would judge
            // another address than the one the request reaches.
            throw new IngestionException(
                sprintf('Source URL host %s is a numeric IPv4 spelling; write the address as four decimal numbers (a.b.c.d)', $host),
                1749379467,
            );
        }

        $addresses = $isIpLiteral ? [$host] : $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new IngestionException('Source URL host does not resolve: ' . $host, 1749379462);
        }

        foreach ($addresses as $address) {
            if ($this->isBlocked($address)) {
                // The address stays out of the message: it would tell every module user
                // what an internal name resolves to. The host is the editor's own input.
                $this->logger->warning('Source URL refused: host resolves to a blocked address', [
                    'host'    => $host,
                    'address' => $address,
                ]);

                throw new IngestionException(
                    sprintf('Source URL host %s resolves to a loopback, private, link-local or reserved address', $host),
                    1749379463,
                );
            }
        }

        return $addresses;
    }

    /**
     * One to four dot-separated parts, each decimal, 0x-prefixed hexadecimal or
     * 0-prefixed octal: the inet_aton() shape that transports read as an address.
     */
    private function isNumericIpv4Spelling(string $host): bool
    {
        $parts = explode('.', $host);
        if (count($parts) > 4) {
            return false;
        }

        foreach ($parts as $part) {
            $isNumeric = match (true) {
                $part === ''                                               => false,
                str_starts_with($part, '0x'), str_starts_with($part, '0X') => strlen($part) > 2 && ctype_xdigit(substr($part, 2)),
                str_starts_with($part, '0')                                => strspn($part, '01234567') === strlen($part),
                default                                                    => ctype_digit($part),
            };
            if (!$isNumeric) {
                return false;
            }
        }

        return true;
    }

    private function isBlocked(string $address): bool
    {
        // Not an address we can judge: refuse rather than guess.
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        $packed = $this->embeddedIpv4((string) inet_pton($address));

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->inRange($packed, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The IPv4 address an IPv6 address carries, packed, for the forms that reach
     * an IPv4 host: IPv4-mapped (::ffff:a.b.c.d), IPv4-compatible (::a.b.c.d,
     * which also holds :: and ::1), the NAT64 well-known prefix 64:ff9b::/96 and
     * 6to4 2002::/16. Any other address comes back unchanged.
     */
    private function embeddedIpv4(string $packed): string
    {
        if (strlen($packed) !== 16) {
            return $packed;
        }

        $zeros = str_repeat("\0", 10);

        return match (true) {
            str_starts_with($packed, $zeros . "\xff\xff"),
            str_starts_with($packed, $zeros . "\0\0"),
            str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8)) => substr($packed, 12),
            str_starts_with($packed, "\x20\x02")                               => substr($packed, 2, 4),
            default                                                            => $packed,
        };
    }

    private function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked    = (string) inet_pton($network);
        if (strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits      = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        if (strncmp($packed, $networkPacked, $fullBytes) !== 0) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
    }
}
