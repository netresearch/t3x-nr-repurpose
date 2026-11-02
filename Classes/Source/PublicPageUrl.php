<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Source;

/** The public URL of a page, whether the site configuration named its host, and that site. */
final readonly class PublicPageUrl
{
    public function __construct(
        public string $url,
        public bool $hostFromSite,
        // The identifier of the page's site.
        public string $site = '',
    ) {}
}
