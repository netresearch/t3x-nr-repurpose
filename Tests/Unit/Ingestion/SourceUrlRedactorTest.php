<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrRepurpose\Ingestion\SourceUrlRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceUrlRedactorTest extends TestCase
{
    /** @return array<string, array{string, string}> input, redacted */
    public static function urls(): array
    {
        return [
            'plain URL unchanged'           => ['https://example.com/reports/q1.pdf', 'https://example.com/reports/q1.pdf'],
            'host only'                     => ['https://example.com', 'https://example.com'],
            'user name removed'             => ['https://user@example.com/doc', 'https://example.com/doc'],
            'user name and password'        => ['https://user:secret@example.com/doc', 'https://example.com/doc'],
            'query removed'                 => ['https://example.com/doc?token=abc&x=1', 'https://example.com/doc'],
            'fragment removed'              => ['https://example.com/doc#frag', 'https://example.com/doc'],
            'port kept'                     => ['http://example.com:8080/doc', 'http://example.com:8080/doc'],
            'IPv6 host and port kept'       => ['https://[2001:db8::1]:8443/p?q=1', 'https://[2001:db8::1]:8443/p'],
            'everything at once'            => ['https://user:secret@example.com:8443/doc.pdf?token=abc#frag', 'https://example.com:8443/doc.pdf'],
            'at sign in the user part'      => ['https://a@evil.example@example.com/', 'https://example.com/'],
            'not a URL'                     => ['not a url', SourceUrlRedactor::PLACEHOLDER],
            'no scheme, password in path'   => ['user:secret@example.com/doc', SourceUrlRedactor::PLACEHOLDER],
            'scheme-relative with password' => ['//user:secret@example.com/doc', SourceUrlRedactor::PLACEHOLDER],
            'no host'                       => ['file:///etc/passwd', SourceUrlRedactor::PLACEHOLDER],
            'unparseable'                   => ['https:///doc?token=abc', SourceUrlRedactor::PLACEHOLDER],
            'empty'                         => ['', SourceUrlRedactor::PLACEHOLDER],
        ];
    }

    #[DataProvider('urls')]
    public function testRedactsTheUrl(string $url, string $expected): void
    {
        self::assertSame($expected, SourceUrlRedactor::redact($url));
    }
}
