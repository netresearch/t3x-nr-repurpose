<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

/**
 * Resolves a host name to the IP addresses a connection to it may reach. A seam
 * so RemoteSourceGuard can be tested without the network.
 */
interface HostResolverInterface
{
    /**
     * @return list<string> every IPv4 and IPv6 address the name resolves to; empty when none
     */
    public function resolve(string $host): array;
}
