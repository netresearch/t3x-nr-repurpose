<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Social;

/**
 * An approved, due social post as a publishing channel receives it.
 */
final readonly class SocialPost
{
    /**
     * @param array<string, mixed> $aiLabel the artifact's aiLabel block (ADR-005)
     */
    public function __construct(
        public int $artifactUid,
        public int $jobUid,
        /** linkedin, x or instagram */
        public string $platform,
        public string $text,
        public int $publishAt,
        public string $sourceUrl,
        public array $aiLabel,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artifactUid' => $this->artifactUid,
            'jobUid'      => $this->jobUid,
            'platform'    => $this->platform,
            'text'        => $this->text,
            'publishAt'   => gmdate('Y-m-d\TH:i:s\Z', $this->publishAt),
            'sourceUrl'   => $this->sourceUrl,
            'aiGenerated' => true,
            'aiLabel'     => $this->aiLabel,
        ];
    }
}
