<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Netresearch\NrRepurpose\Ingestion\HostResolverInterface;
use Netresearch\NrRepurpose\Ingestion\RemoteSourceGuard;

/**
 * A resolver with fixed answers, so the SSRF guard is tested without DNS.
 * Unknown hosts resolve to nothing.
 */
final class StaticHostResolver implements HostResolverInterface
{
    /** @var list<string> */
    public array $asked = [];

    /** @param array<string, list<string>> $answers host => addresses */
    public function __construct(private readonly array $answers = []) {}

    /** A guard that lets every host through as one public address (example.com's). */
    public static function publicGuard(): RemoteSourceGuard
    {
        return new RemoteSourceGuard(new class implements HostResolverInterface {
            public function resolve(string $host): array
            {
                return ['93.184.215.14'];
            }
        });
    }

    public function resolve(string $host): array
    {
        $this->asked[] = $host;

        return $this->answers[$host] ?? [];
    }
}
