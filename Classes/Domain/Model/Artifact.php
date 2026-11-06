<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Model;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\FailureReason;
use Netresearch\NrRepurpose\Domain\Enum\PublishStatus;
use Netresearch\NrRepurpose\Domain\Enum\ReviewStatus;
use Netresearch\NrRepurpose\SourceReview\SourceReviewResult;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Artifact extends AbstractEntity
{
    protected int $job = 0;

    protected string $type = '';

    protected string $variant = 'default';

    protected int $fileUid = 0;

    protected int $subtitleFileUid = 0;

    protected string $sourceHtml = '';

    protected string $scriptText = '';

    protected string $status = 'pending';

    protected string $errorMessage = '';

    protected string $failureReason = '';

    protected string $metadata = '';

    protected string $reviewStatus = '';

    protected int $reviewedBy = 0;

    protected int $reviewedAt = 0;

    protected int $publishAt = 0;

    protected string $publishStatus = '';

    protected int $publishedAt = 0;

    protected string $publishError = '';

    public function getTypeEnum(): ArtifactType
    {
        return ArtifactType::from($this->type);
    }

    public function getStatusEnum(): ArtifactStatus
    {
        return ArtifactStatus::from($this->status);
    }

    public function getJob(): int
    {
        return $this->job;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getVariant(): string
    {
        return $this->variant;
    }

    public function getFileUid(): int
    {
        return $this->fileUid;
    }

    public function getSubtitleFileUid(): int
    {
        return $this->subtitleFileUid;
    }

    public function getSourceHtml(): string
    {
        return $this->sourceHtml;
    }

    public function getScriptText(): string
    {
        return $this->scriptText;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * A row written before reasons were stored reads as Unknown: rows of older versions can hold
     * a provider's words in their message.
     */
    public function getFailure(): ?FailureReason
    {
        return $this->getStatusEnum() === ArtifactStatus::Failed
            ? FailureReason::tryFrom($this->failureReason) ?? FailureReason::Unknown
            : null;
    }

    public function getReviewStatusEnum(): ReviewStatus
    {
        return ReviewStatus::tryFrom($this->reviewStatus) ?? ReviewStatus::Open;
    }

    public function getReviewStatus(): string
    {
        return $this->reviewStatus;
    }

    public function getReviewedBy(): int
    {
        return $this->reviewedBy;
    }

    public function getReviewedAt(): int
    {
        return $this->reviewedAt;
    }

    public function getPublishAt(): int
    {
        return $this->publishAt;
    }

    /** The publishing time as the value of a datetime-local input (server time), or ''. */
    public function getPublishAtLocal(): string
    {
        return $this->publishAt > 0 ? date('Y-m-d\TH:i', $this->publishAt) : '';
    }

    public function getPublishStatusEnum(): PublishStatus
    {
        return PublishStatus::tryFrom($this->publishStatus) ?? PublishStatus::None;
    }

    public function getPublishStatus(): string
    {
        return $this->publishStatus;
    }

    public function getPublishedAt(): int
    {
        return $this->publishedAt;
    }

    public function getPublishError(): string
    {
        return $this->publishError;
    }

    public function getMetadata(): string
    {
        return $this->metadata;
    }

    /**
     * The decoded metadata JSON for Fluid (e.g. slide role/index/total on story slides).
     * Empty or invalid metadata decodes to an empty array; a JSON list keeps its int keys.
     *
     * @return array<mixed>
     */
    public function getMetadataArray(): array
    {
        $decoded = json_decode($this->metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected string $sourceReview = '';

    public function getSourceReview(): string
    {
        return $this->sourceReview;
    }

    /** Computed view data; the persisted property remains a JSON string. */
    public function getSourceReviewResult(): SourceReviewResult
    {
        return SourceReviewResult::fromJson($this->sourceReview, $this->scriptText);
    }
}
