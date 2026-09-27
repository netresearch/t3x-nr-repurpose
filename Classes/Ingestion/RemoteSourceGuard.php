<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SSRF guard for the editor-supplied source URL (url and pdf_url jobs). The
 * worker fetches that URL from inside the hosting network, so it must not reach
 * the host itself, the private network or a cloud metadata endpoint.
 *
 * Allowed: http and https to a host whose every resolved address is public.
 * Refused: any other scheme, a host that does not resolve, and a host or IP
 * literal with any address in loopback, RFC 1918, CGNAT, link-local (which
 * holds 169.254.169.254), unique-local IPv6, unspecified, multicast or the
 * reserved 240.0.0.0/4 block. IPv4-mapped IPv6 addresses are judged as IPv4.
 *
 * TYPO3 core offers no equivalent for the injected client: the container builds
 * it through GuzzleClientFactory::getClient() without a context, so the
 * allowed_hosts middleware is never attached, and that middleware is a host
 * allow-list, not a private-range block.
 *
 * The check runs before the request; the client resolves the name again when it
 * connects (see the residual DNS-rebinding risk in the PR that added this).
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
        '::/128',          // unspecified
        '::1/128',         // loopback
        'fc00::/7',        // unique local
        'fe80::/10',       // link-local
        'ff00::/8',        // multicast
    ];

    public function __construct(
        private HostResolverInterface $resolver,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @throws IngestionException when the URL must not be fetched
     */
    public function assertAllowed(UriInterface $uri): void
    {
        $scheme = strtolower($uri->getScheme());
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new IngestionException(
                sprintf('Source URL scheme "%s" is not allowed, only http and https: %s', $scheme, $uri),
                1749379460,
            );
        }

        $host = trim($uri->getHost(), '[]');
        if ($host === '') {
            throw new IngestionException('Source URL has no host: ' . $uri, 1749379461);
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolver->resolve($host);
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
    }

    private function isBlocked(string $address): bool
    {
        // Not an address we can judge: refuse rather than guess.
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        $packed = (string) inet_pton($address);

        // ::ffff:a.b.c.d reaches the IPv4 host a.b.c.d.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->inRange($packed, $range)) {
                return true;
            }
        }

        return false;
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
