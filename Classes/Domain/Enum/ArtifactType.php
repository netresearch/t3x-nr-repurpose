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
    case Stub      = 'stub';

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
            self::Podcast   => 'mimetypes-media-audio',
            self::Schaubild => 'content-widget-chart',
            self::Story     => 'actions-device-mobile',
            self::Stub      => 'miscellaneous-placeholder',
        };
    }
}
