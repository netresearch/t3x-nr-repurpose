<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

/**
 * What the worker did with a page source: how many pages went into the text, which were left
 * out for which reason, and whether a limit cut the selection. Stored as JSON on the job
 * (source_report) because the result of a successful job has no other place for it: finishJob()
 * clears error_message.
 *
 * The result view renders the numbers through its own labels, so a report written in one
 * backend language reads correctly in another.
 */
final readonly class SourceReport
{
    public function __construct(
        public int $pagesUsed = 0,
        public int $pagesFailed = 0,
        public int $skippedHidden = 0,
        public int $skippedRestricted = 0,
        public int $skippedExcluded = 0,
        public int $skippedNoAccess = 0,
        // Pages the text limit left out; the page limit stops the tree walk, so it has no count.
        public int $pagesLeftOut = 0,
        public bool $cutByPageLimit = false,
        public bool $cutByTextLimit = false,
        public int $maxPages = 0,
        public int $maxChars = 0,
    ) {}

    public static function fromJson(string $json): self
    {
        // A broken or empty column decodes to null: no report.
        $data = json_decode($json, true, 4);
        if (!is_array($data)) {
            return new self();
        }

        $int = static fn (string $key): int => is_int($data[$key] ?? null) ? $data[$key] : 0;

        return new self(
            pagesUsed: $int('pagesUsed'),
            pagesFailed: $int('pagesFailed'),
            skippedHidden: $int('skippedHidden'),
            skippedRestricted: $int('skippedRestricted'),
            skippedExcluded: $int('skippedExcluded'),
            skippedNoAccess: $int('skippedNoAccess'),
            pagesLeftOut: $int('pagesLeftOut'),
            cutByPageLimit: ($data['cutByPageLimit'] ?? false) === true,
            cutByTextLimit: ($data['cutByTextLimit'] ?? false) === true,
            maxPages: $int('maxPages'),
            maxChars: $int('maxChars'),
        );
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }

    /** Whether a page or text limit cut the selection. */
    public function isCut(): bool
    {
        return $this->cutByPageLimit || $this->cutByTextLimit;
    }

    /** Whether any page of the selection is missing from the text, for whatever reason. */
    public function hasOmissions(): bool
    {
        return $this->isCut()
            || $this->pagesFailed + $this->skippedHidden + $this->skippedRestricted + $this->skippedExcluded + $this->skippedNoAccess > 0;
    }
}
