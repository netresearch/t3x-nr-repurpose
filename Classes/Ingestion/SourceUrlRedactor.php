<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

/**
 * The editor's source URL as it may appear in a message or a log line. The job keeps
 * the URL as entered, and the client needs its user name, password and query to fetch
 * it; the job's error_message is shown to every module user, so a message names the
 * URL without them.
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
}
