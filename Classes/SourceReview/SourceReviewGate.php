<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\SourceReview;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;

/**
 * Persist the verdict and exact checked text before enforcing it.
 *
 * @internal
 */
final readonly class SourceReviewGate
{
    public function __construct(
        private SourceReviewService $service,
        private SourceReviewPolicy $policy,
        private JobProcessingRepository $jobs,
        private ?SourceReviewPointer $sourcePointer = null,
    ) {}

    /** @return array<string, mixed> */
    public function brief(
        JobSnapshot $job,
        SourceDocument $document,
        ContentBrief $brief,
        ?string $sourcePointerHash = null,
    ): array {
        $candidate = json_encode(
            [
                'title'     => $brief->title,
                'summary'   => $brief->summary,
                'keyPoints' => $brief->keyPoints,
                'sections'  => $brief->sections,
                'audience'  => $brief->audience,
                'language'  => $brief->language,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        $row = $this->jobs->findRow($job->uid);
        if ($row === null) {
            throw new SourceReviewRejectedException();
        }

        $previous = $row['source_review'] ?? null;
        if ($previous !== null && !is_string($previous)) {
            throw new SourceReviewRejectedException();
        }

        $previousReview = is_string($previous) ? json_decode($previous, true) : null;
        $forceEnforce   = is_array($previousReview) && ($previousReview['mode'] ?? null) === 'enforce';
        $review         = $this->service->review(
            $candidate,
            $document,
            $job->beUser,
            'content brief',
            $forceEnforce,
        );
        $review['sourcePointerHash'] = $sourcePointerHash ?? $this->captureSource($row)->hash;
        if (!$this->jobs->compareAndSetSourceReview($job->uid, $review, $previous)) {
            throw new SourceReviewSupersededException();
        }

        if (!$this->policy->allowsBrief($review)) {
            throw new SourceReviewRejectedException();
        }

        return $review;
    }

    public function artifact(
        GenerationContext $ctx,
        int $artifactUid,
        string $candidate,
        string $scope,
    ): void {
        $review = $this->service->review(
            $candidate,
            $ctx->document,
            $ctx->beUser,
            $scope,
            ($ctx->briefReview['mode'] ?? null) === 'enforce',
        );
        $this->jobs->updateArtifact(
            $artifactUid,
            [
                'script_text'   => $candidate,
                'source_review' => json_encode($review, JSON_THROW_ON_ERROR),
            ],
        );
        if (!$this->policy->allowsArtifact($review, $candidate, $ctx->briefReview)) {
            throw new SourceReviewRejectedException();
        }
    }

    public static function htmlText(string $html): string
    {
        $withoutStyle = preg_replace('~<(style|script)\b[^>]*>.*?</\1>~is', '', $html) ?? '';

        // Keep adjacent headings/cells separate: strip_tags alone joins their words.
        // Inline tags get no separator, so "1.<strong>5</strong>%" stays "1.5%"; sibling
        // elements such as "<span>2026</span><span>15%</span>" may be laid out apart, so a
        // closing tag directly followed by an opening one still gets one.
        $separated = preg_replace(
            ['~<(?:/?(?:address|article|aside|blockquote|caption|dd|div|dl|dt|figcaption|figure|footer|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|tbody|td|tfoot|th|thead|tr|ul)\b[^>]*|br\b[^>]*)>~i', '~(</[a-z][a-z0-9]*>)(?=<[a-z])~i'],
            ['$0 ', '$1 '],
            $withoutStyle,
        ) ?? '';

        return trim(
            html_entity_decode(
                strip_tags($separated),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            ),
        );
    }

    /** Capture before ingestion; later edits must not become evidence for an older document.
     * @param array<string, mixed> $row
     */
    public function captureSource(array $row): SourceCapture
    {
        return $this->sourcePointer?->capture($row) ?? new SourceCapture(SourceReviewPolicy::sourcePointerHash($row));
    }
}
