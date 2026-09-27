<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

/**
 * The system resolver: IPv4 through gethostbynamel(), which goes through the C
 * library like the HTTP client does (so /etc/hosts entries and numeric forms
 * such as "2130706433" resolve the same way), plus the AAAA records from DNS.
 */
final class DnsHostResolver implements HostResolverInterface
{
    public function resolve(string $host): array
    {
        $addresses = [];

        // A failed lookup raises a warning ("DNS Query failed"); an unresolvable host
        // is an answer here (the guard refuses it), not an error.
        set_error_handler(static fn (): bool => true);
        try {
            $ipv4    = gethostbynamel($host);
            $records = dns_get_record($host, DNS_AAAA);
        } finally {
            restore_error_handler();
        }

        if (is_array($ipv4)) {
            foreach ($ipv4 as $address) {
                $addresses[] = $address;
            }
        }

        if (is_array($records)) {
            foreach ($records as $record) {
                if (is_string($record['ipv6'] ?? null)) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
