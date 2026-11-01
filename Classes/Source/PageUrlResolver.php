<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Source;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\InvalidRouteArgumentsException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The public URL of a page in the default language. A site whose base has no host (`base: /`, it
 * answers on whatever host a request names) gives only a path, and the worker has no request:
 * $origin supplies scheme and host then. They come from the request that created the job, so
 * such a URL is not the site's word for its host and goes through the guard for editor URLs.
 */
class PageUrlResolver
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * @param string $origin scheme and host, for example https://www.example.org
     *
     * @throws IngestionException for a page without a site, or without a host from either side
     */
    public function resolve(int $pageUid, string $origin): PublicPageUrl
    {
        try {
            $uri = $this->siteFinder->getSiteByPageId($pageUid)->getRouter()->generateUri($pageUid, ['_language' => 0]);
        } catch (SiteNotFoundException|InvalidRouteArgumentsException $e) {
            throw new IngestionException(sprintf('Page %d has no public URL: it belongs to no site', $pageUid), 1749379485, $e);
        }

        if ($uri->getHost() !== '') {
            return new PublicPageUrl((string) $uri, true);
        }

        if ($origin === '') {
            throw new IngestionException(sprintf('Page %d has no public URL: its site base names no host', $pageUid), 1749379486);
        }

        return new PublicPageUrl(rtrim($origin, '/') . $uri, false);
    }

    /** Scheme, host and port of an absolute URL; '' when it has no host. */
    public function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['host'] ?? '') === '') {
            return '';
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $port;
    }
}
