<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A request RemoteSourceGuard allowed, together with the addresses its host resolved
 * to when the guard checked it. BoundedResponseReader::send() connects to exactly
 * these addresses, so the transfer reaches the host the guard judged and the HTTP
 * client never asks DNS again. With an HTTP proxy configured
 * ($GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy']) curl connects to the proxy, and the
 * proxy resolves the name itself; restricting its targets is then the proxy's task.
 */
final readonly class GuardedRequest
{
    /**
     * @param non-empty-list<string> $addresses the checked addresses of the request's host
     */
    public function __construct(
        public RequestInterface $request,
        public array $addresses,
    ) {}

    public function withHeader(string $name, string $value): self
    {
        return new self($this->request->withHeader($name, $value), $this->addresses);
    }

    public function withBody(StreamInterface $body): self
    {
        return new self($this->request->withBody($body), $this->addresses);
    }

    /**
     * The curl options that make curl connect to the checked addresses: one
     * CURLOPT_RESOLVE entry "host:port:address[,address]" for the request's host and
     * port, IPv6 addresses in brackets. The request URI keeps the host name, so the Host
     * header, TLS SNI and certificate verification stay those of the name.
     *
     * An IP literal is not looked up by curl at all, so it needs no entry.
     *
     * @return array<int, list<string>>
     */
    public function curlOptions(): array
    {
        $uri  = $this->request->getUri();
        $host = $uri->getHost();
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $port      = $uri->getPort() ?? (strtolower($uri->getScheme()) === 'https' ? 443 : 80);
        $addresses = array_map(
            static fn (string $address): string => str_contains($address, ':') ? '[' . $address . ']' : $address,
            $this->addresses,
        );

        return [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, implode(',', $addresses))]];
    }
}
