<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

/**
 * The editor's source URL as it may appear outside the fetch. The job keeps the URL as
 * entered, and the client needs its user name, password and query to fetch it; what
 * module users, the text model or generated files see goes through redact(), what the
 * social webhook publishes through withoutCredentials().
 */
final class SourceUrlRedactor
{
    /** Stands in for a string that is not an absolute URL with a host. */
    public const PLACEHOLDER = '(URL not shown)';

    /**
     * scheme://host[:port]/path, rebuilt from the parsed parts: user name, password,
     * query and fragment are left out. Anything without a scheme and a host gives
     * PLACEHOLDER, since parse_url() files the password of "user:pass@host/x" under
     * scheme and path.
     */
    public static function redact(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return self::PLACEHOLDER;
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '');
    }

    /**
     * The URL for a place that publishes it (the social webhook): only the user name and
     * password are left out; scheme, host, port, path, query and fragment stay, since a
     * query can be part of the page's address (index.php?id=5). A string without a scheme
     * and a host comes back unchanged unless an at sign precedes its first "/", "?" or
     * "#" (after an optional "scheme:" and "//"), where a user name would sit; then it
     * gives PLACEHOLDER.
     */
    public static function withoutCredentials(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            $rest = (string) preg_replace('~^[A-Za-z][A-Za-z0-9+.-]*:~', '', $url, 1);
            $rest = str_starts_with($rest, '//') ? substr($rest, 2) : $rest;

            return str_contains(substr($rest, 0, strcspn($rest, '/?#')), '@') ? self::PLACEHOLDER : $url;
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
}
