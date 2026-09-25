<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

enum ArtifactType: string
{
    case Podcast   = 'podcast';
    case Schaubild = 'schaubild';
    case Story     = 'story';

    // Text formats. The values must fit the 16-character `type` column.
    case ExecutiveSummary = 'exec_summary';
    case Faq              = 'faq';
    case SocialPost       = 'social_post';
    case Newsletter       = 'newsletter';

    case Stub = 'stub';

    /**
     * LLL key (locallang.xlf) for the human-readable type label.
     * `get` prefix so Fluid `{summary.type.labelKey}` resolves it.
     */
    public function getLabelKey(): string
    {
        return 'artifact.' . $this->value;
    }

    /** TYPO3 core icon identifier representing this artifact type in the backend. */
    public function getIconIdentifier(): string
    {
        return match ($this) {
            self::Podcast          => 'mimetypes-media-audio',
            self::Schaubild        => 'content-widget-chart',
            self::Story            => 'actions-device-mobile',
            self::ExecutiveSummary => 'content-text',
            self::Faq              => 'content-accordion',
            self::SocialPost       => 'actions-share-alt',
            self::Newsletter       => 'actions-envelope',
            self::Stub             => 'miscellaneous-placeholder',
        };
    }
}
