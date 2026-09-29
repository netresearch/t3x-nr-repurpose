<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\ValueObject;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\Enum\PdfMode;
use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Exception\MalformedJobRowException;

/**
 * The job row as the pipeline reads it (JobProcessingRepository::findRow()), typed once.
 * fromRow() is the only place that reads the raw row; ingestion, analysis and the
 * generators read these properties.
 *
 * A missing column takes the value the pipeline assumed before this object existed:
 * status "queued", source_type "url", theme "nr", pdf_mode Auto, 0 for be_user, an
 * empty string for source_value and no snippet selection, and false for
 * every want_* flag. A present value of the wrong type, an unknown status or
 * source_type, and a missing or non-positive uid are rejected.
 */
final readonly class JobSnapshot
{
    public function __construct(
        public int $uid,
        public JobStatus $status,
        public SourceType $sourceType,
        // The URL of a url or pdf_url job; '' when the column is NULL.
        public string $sourceValue,
        public PdfMode $pdfMode,
        public string $theme,   // 'nr' | 'neutral', not validated (as before)
        public int $beUser,
        public PromptSnippetSelection $promptSnippets,
        public bool $wantPodcast = false,
        public bool $wantSchaubild = false,
        public bool $wantStory = false,
        public bool $wantVideo = false,
        public bool $wantExecSummary = false,
        public bool $wantFaq = false,
        public bool $wantSocialPost = false,
        public bool $wantNewsletter = false,
        public bool $wantSlideDeck = false,
        public bool $wantHandout = false,
    ) {}

    /**
     * @param array<string,mixed> $row
     *
     * @throws MalformedJobRowException
     */
    public static function fromRow(array $row): self
    {
        $uid = self::unsignedInt($row, 'uid', null);
        if ($uid === 0) {
            throw new MalformedJobRowException('Job row column "uid" must be a positive integer', 1790500001);
        }

        $status = JobStatus::tryFrom(self::string($row, 'status', JobStatus::Queued->value));
        if ($status === null) {
            throw new MalformedJobRowException('Job row column "status" holds an unknown status', 1790500002);
        }

        $sourceType = SourceType::tryFrom(self::string($row, 'source_type', SourceType::Url->value));
        if ($sourceType === null) {
            throw new MalformedJobRowException('Job row column "source_type" holds an unknown source type', 1790500003);
        }

        return new self(
            uid: $uid,
            status: $status,
            sourceType: $sourceType,
            sourceValue: self::string($row, 'source_value', ''),
            pdfMode: PdfMode::fromJobValue(self::string($row, 'pdf_mode', PdfMode::Auto->value)),
            theme: self::string($row, 'theme', 'nr'),
            beUser: self::unsignedInt($row, 'be_user', 0),
            promptSnippets: PromptSnippetSelection::fromJson(self::string($row, 'prompt_snippets', '')),
            wantPodcast: self::flag($row, 'want_podcast'),
            wantSchaubild: self::flag($row, 'want_schaubild'),
            wantStory: self::flag($row, 'want_story'),
            wantVideo: self::flag($row, 'want_video'),
            wantExecSummary: self::flag($row, 'want_exec_summary'),
            wantFaq: self::flag($row, 'want_faq'),
            wantSocialPost: self::flag($row, 'want_social_post'),
            wantNewsletter: self::flag($row, 'want_newsletter'),
            wantSlideDeck: self::flag($row, 'want_slide_deck'),
            wantHandout: self::flag($row, 'want_handout'),
        );
    }

    /** Whether the editor asked for this artifact type; the stub has no column and is never wanted. */
    public function wants(ArtifactType $type): bool
    {
        return match ($type) {
            ArtifactType::Podcast          => $this->wantPodcast,
            ArtifactType::Schaubild        => $this->wantSchaubild,
            ArtifactType::Story            => $this->wantStory,
            ArtifactType::Video            => $this->wantVideo,
            ArtifactType::ExecutiveSummary => $this->wantExecSummary,
            ArtifactType::Faq              => $this->wantFaq,
            ArtifactType::SocialPost       => $this->wantSocialPost,
            ArtifactType::Newsletter       => $this->wantNewsletter,
            ArtifactType::SlideDeck        => $this->wantSlideDeck,
            ArtifactType::Handout          => $this->wantHandout,
            ArtifactType::Stub             => false,
        };
    }

    /**
     * An unsigned integer column. The database driver returns an int or a string of
     * digits depending on the platform; both are accepted. $default null makes the
     * column required.
     *
     * @param array<string,mixed> $row
     */
    private static function unsignedInt(array $row, string $column, ?int $default): int
    {
        $value = $row[$column] ?? null;
        if ($value === null && $default !== null) {
            return $default;
        }

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new MalformedJobRowException(
            sprintf('Job row column "%s" must be an unsigned integer', $column),
            1790500004,
        );
    }

    /**
     * A string column; NULL and a missing column take $default.
     *
     * @param array<string,mixed> $row
     */
    private static function string(array $row, string $column, string $default): string
    {
        $value = $row[$column] ?? null;
        if ($value === null) {
            return $default;
        }

        if (!is_string($value)) {
            throw new MalformedJobRowException(
                sprintf('Job row column "%s" must be a string', $column),
                1790500005,
            );
        }

        return $value;
    }

    /**
     * A want_* checkbox column (smallint 0/1); a missing column is false.
     *
     * @param array<string,mixed> $row
     */
    private static function flag(array $row, string $column): bool
    {
        return self::unsignedInt($row, $column, 0) !== 0;
    }
}
