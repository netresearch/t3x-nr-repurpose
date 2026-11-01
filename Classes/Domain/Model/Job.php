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
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\Enum\PdfMode;
use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Domain\ValueObject\ArtifactTypeSummary;
use Netresearch\NrRepurpose\Domain\ValueObject\PromptSnippetSelection;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceReport;
use Netresearch\NrRepurpose\Ingestion\SourceUrlRedactor;
use Netresearch\NrRepurpose\Utility\ScalarCast;
use TYPO3\CMS\Extbase\Attribute\ORM\Lazy;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

class Job extends AbstractEntity
{
    private const int DEEPEST_NAMED_LEVEL = 4;

    /**
     * Two hours without a write, in seconds. Every step writes the job, and the doctrine transport
     * hands an unfinished message to a worker again after one hour, so this is a stopped worker.
     */
    private const int STALLED_AFTER = 7200;

    protected string $sourceType = 'url';

    protected string $sourceValue = '';

    // The public URL of a picked page when the job was made. The social post links to it, and it
    // gives the worker the host of a site whose base names none.
    protected string $sourceUrl = '';

    // What the module shows for a picked page or file: title and id at the time of the pick.
    protected string $sourceLabel = '';

    // Levels below the picked page; 0 is the page alone.
    protected int $sourceDepth = 0;

    // JSON of the SourceReport the worker writes for a page source.
    protected string $sourceReport = '';

    protected string $theme = 'nr';

    protected string $pdfMode = 'auto';

    protected bool $wantPodcast = true;

    protected bool $wantSchaubild = true;

    protected bool $wantStory = true;

    // The text formats are opt-in: an upgraded installation must not start making
    // four extra LLM calls per job without anyone choosing that.
    protected bool $wantExecSummary = false;

    protected bool $wantFaq = false;

    protected bool $wantSocialPost = false;

    protected bool $wantNewsletter = false;

    // The document formats are opt-in for the same reason.
    protected bool $wantSlideDeck = false;

    protected bool $wantHandout = false;

    // The story video is opt-in: it adds an ffmpeg render to every story.
    protected bool $wantVideo = false;

    protected string $promptSnippets = '';

    protected string $status = 'queued';

    protected int $progress = 0;

    protected string $currentStep = '';

    protected string $errorMessage = '';

    protected string $failureReason = '';

    protected int $tstamp = 0;

    protected string $languageDetected = '';

    protected int $beUser = 0;

    /**
     * Lazy: the job list maps a page of jobs and must not load every job's artifacts and
     * file references with them; it reads the artifact summaries in one grouped query
     * (ArtifactRepository::findTypeSummariesByJobs()).
     *
     * @var ObjectStorage<Artifact>
     */
    #[Lazy]
    protected ObjectStorage $artifacts;

    /** @var ObjectStorage<FileReference> */
    #[Lazy]
    protected ObjectStorage $sourcePdf;

    public function __construct()
    {
        $this->artifacts = new ObjectStorage();
        $this->sourcePdf = new ObjectStorage();
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $sourceType): void
    {
        $this->sourceType = $sourceType;
    }

    public function getSourceTypeEnum(): SourceType
    {
        return SourceType::from($this->sourceType);
    }

    public function setSourceTypeEnum(SourceType $type): void
    {
        $this->sourceType = $type->value;
    }

    public function getStatusEnum(): JobStatus
    {
        return JobStatus::from($this->status);
    }

    public function setStatusEnum(JobStatus $status): void
    {
        $this->status = $status->value;
    }

    public function getSourceValue(): string
    {
        return $this->sourceValue;
    }

    public function setSourceValue(string $sourceValue): void
    {
        $this->sourceValue = $sourceValue;
    }

    public function getSourceUrl(): string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(string $sourceUrl): void
    {
        $this->sourceUrl = $sourceUrl;
    }

    public function getSourceLabel(): string
    {
        return $this->sourceLabel;
    }

    public function setSourceLabel(string $sourceLabel): void
    {
        $this->sourceLabel = $sourceLabel;
    }

    public function getSourceDepth(): int
    {
        return $this->sourceDepth;
    }

    public function setSourceDepth(int $sourceDepth): void
    {
        $this->sourceDepth = $sourceDepth;
    }

    public function getSourceReport(): string
    {
        return $this->sourceReport;
    }

    public function getSourceSummary(): SourceReport
    {
        return SourceReport::fromJson($this->sourceReport);
    }

    /**
     * What the module's pages show for the source: the title and id of a picked page or file,
     * otherwise the source URL without user name, password, query and fragment. Empty for a
     * job without either (a PDF attached in the record view).
     */
    public function getSourceValueForDisplay(): string
    {
        if ($this->sourceLabel !== '') {
            return $this->sourceLabel;
        }

        return $this->sourceValue === '' ? '' : SourceUrlRedactor::redact($this->sourceValue);
    }

    /** Whether the source is a page of this installation, for the result view's depth row. */
    public function isPageSource(): bool
    {
        return $this->sourceType === SourceType::Page->value;
    }

    /** The last part of the form's label key for the depth: the number, or "all" above 4 levels. */
    public function getSourceDepthLabelKey(): string
    {
        return $this->sourceDepth > self::DEEPEST_NAMED_LEVEL ? 'all' : (string) $this->sourceDepth;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): void
    {
        $this->theme = $theme;
    }

    public function getPdfMode(): string
    {
        return $this->pdfMode;
    }

    public function setPdfMode(string $pdfMode): void
    {
        $this->pdfMode = $pdfMode;
    }

    public function getPdfModeEnum(): PdfMode
    {
        return PdfMode::fromJobValue($this->pdfMode);
    }

    public function setPdfModeEnum(PdfMode $mode): void
    {
        $this->pdfMode = $mode->value;
    }

    /** @return ObjectStorage<FileReference> */
    public function getSourcePdf(): ObjectStorage
    {
        return $this->sourcePdf;
    }

    public function getSourcePdfCount(): int
    {
        return $this->sourcePdf->count();
    }

    public function isWantPodcast(): bool
    {
        return $this->wantPodcast;
    }

    public function setWantPodcast(bool $wantPodcast): void
    {
        $this->wantPodcast = $wantPodcast;
    }

    public function isWantSchaubild(): bool
    {
        return $this->wantSchaubild;
    }

    public function setWantSchaubild(bool $wantSchaubild): void
    {
        $this->wantSchaubild = $wantSchaubild;
    }

    public function isWantStory(): bool
    {
        return $this->wantStory;
    }

    public function setWantStory(bool $wantStory): void
    {
        $this->wantStory = $wantStory;
    }

    public function isWantExecSummary(): bool
    {
        return $this->wantExecSummary;
    }

    public function setWantExecSummary(bool $wantExecSummary): void
    {
        $this->wantExecSummary = $wantExecSummary;
    }

    public function isWantFaq(): bool
    {
        return $this->wantFaq;
    }

    public function setWantFaq(bool $wantFaq): void
    {
        $this->wantFaq = $wantFaq;
    }

    public function isWantSocialPost(): bool
    {
        return $this->wantSocialPost;
    }

    public function setWantSocialPost(bool $wantSocialPost): void
    {
        $this->wantSocialPost = $wantSocialPost;
    }

    public function isWantNewsletter(): bool
    {
        return $this->wantNewsletter;
    }

    public function setWantNewsletter(bool $wantNewsletter): void
    {
        $this->wantNewsletter = $wantNewsletter;
    }

    public function isWantSlideDeck(): bool
    {
        return $this->wantSlideDeck;
    }

    public function setWantSlideDeck(bool $wantSlideDeck): void
    {
        $this->wantSlideDeck = $wantSlideDeck;
    }

    public function isWantHandout(): bool
    {
        return $this->wantHandout;
    }

    public function setWantHandout(bool $wantHandout): void
    {
        $this->wantHandout = $wantHandout;
    }

    public function isWantVideo(): bool
    {
        return $this->wantVideo;
    }

    public function setWantVideo(bool $wantVideo): void
    {
        $this->wantVideo = $wantVideo;
    }

    public function getPromptSnippets(): string
    {
        return $this->promptSnippets;
    }

    public function setPromptSnippets(string $promptSnippets): void
    {
        $this->promptSnippets = $promptSnippets;
    }

    public function getPromptSnippetSelection(): PromptSnippetSelection
    {
        return PromptSnippetSelection::fromJson($this->promptSnippets);
    }

    public function setPromptSnippetSelection(PromptSnippetSelection $selection): void
    {
        $this->promptSnippets = $selection->toJson();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getProgress(): int
    {
        return $this->progress;
    }

    public function getCurrentStep(): string
    {
        return $this->currentStep;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * What the result view explains: a failure, a partial result, or a job that stands still.
     * Null while a job runs normally and when it finished clean.
     */
    public function getFailure(): ?FailureReason
    {
        $status = $this->getStatusEnum();
        if ($status === JobStatus::PartiallyDone) {
            return FailureReason::SomeFormatsFailed;
        }

        if ($status === JobStatus::Failed) {
            return FailureReason::fromStored($this->failureReason, $this->errorMessage);
        }

        return !$status->isTerminal() && $this->tstamp > 0 && time() - $this->tstamp > self::STALLED_AFTER
            ? FailureReason::WorkerStalled
            : null;
    }

    /** The heading of the explanation: where the run stopped, in the editor's words. */
    public function getFailureTitleKey(): string
    {
        return 'failure.title.' . match (true) {
            $this->getFailure() === FailureReason::WorkerStalled           => 'stalled',
            $this->getStatusEnum() === JobStatus::PartiallyDone            => 'partial',
            in_array($this->currentStep, ['', 'queued'], true)             => 'queued',
            in_array($this->currentStep, ['ingesting', 'analyzing'], true) => $this->currentStep,
            default                                                        => 'generating',
        };
    }

    /** A generator's own detail for a running job; the status already says what a slug says. */
    public function getStepDetail(): string
    {
        return JobStatus::tryFrom($this->currentStep) instanceof JobStatus ? '' : $this->currentStep;
    }

    public function isFailed(): bool
    {
        return $this->getStatusEnum() === JobStatus::Failed;
    }

    /** A new run removes every artifact of the job, so a finished result rules it out. */
    public function isRetryable(): bool
    {
        return $this->isFailed()
            && $this->getFailure()?->isRetryable() === true
            && $this->getDoneArtifactCount() === 0;
    }

    public function getDoneArtifactCount(): int
    {
        $done = 0;
        foreach ($this->artifacts as $artifact) {
            $done += $artifact->getStatus() === ArtifactStatus::Done->value ? 1 : 0;
        }

        return $done;
    }

    public function getTstamp(): int
    {
        return $this->tstamp;
    }

    public function getLanguageDetected(): string
    {
        return $this->languageDetected;
    }

    public function getBeUser(): int
    {
        return $this->beUser;
    }

    public function setBeUser(int $beUser): void
    {
        $this->beUser = $beUser;
    }

    /** @return ObjectStorage<Artifact> */
    public function getArtifacts(): ObjectStorage
    {
        return $this->artifacts;
    }

    /**
     * One aggregate summary per artifact type this job has artifacts for,
     * in enum order.
     *
     * @return list<ArtifactTypeSummary>
     */
    public function getArtifactTypeSummaries(): array
    {
        $pairs = [];
        foreach ($this->artifacts as $artifact) {
            $pairs[] = [$artifact->getType(), $artifact->getStatus()];
        }

        return self::summarizeArtifactStatuses($pairs);
    }

    /**
     * Folds [type, status] pairs of one job's artifacts into one summary per type, in enum
     * order. Shared by getArtifactTypeSummaries() and the job list's grouped query
     * (ArtifactRepository::findTypeSummariesByJobs()), so both show the same aggregate.
     *
     * @param iterable<array{0: string, 1: string}> $pairs
     *
     * @return list<ArtifactTypeSummary>
     */
    public static function summarizeArtifactStatuses(iterable $pairs): array
    {
        $statusesByType = [];
        foreach ($pairs as [$type, $statusValue]) {
            // tryFrom: a corrupted/legacy status string in a single row must not
            // throw and take down the whole job list view — skip that artifact.
            $status = ArtifactStatus::tryFrom($statusValue);
            if ($status === null) {
                continue;
            }

            $statusesByType[$type][] = $status;
        }

        $summaries = [];
        foreach (ArtifactType::cases() as $type) {
            if (isset($statusesByType[$type->value])) {
                $summaries[] = ArtifactTypeSummary::fromStatuses($type, $statusesByType[$type->value]);
            }
        }

        return $summaries;
    }

    /**
     * The story-carousel slides in slide order. The explicit slideIndex each slide carries
     * in its metadata is the authoritative order; rows without it (legacy single-image
     * "default" artifacts) fall back to ascending uid. Used by the result view to render
     * the slide strip.
     *
     * @return list<Artifact>
     */
    public function getStoryArtifacts(): array
    {
        $slides = [];
        foreach ($this->artifacts as $artifact) {
            if ($artifact->getType() === ArtifactType::Story->value) {
                $slides[] = $artifact;
            }
        }

        usort($slides, static function (Artifact $a, Artifact $b): int {
            $bySlideIndex = ScalarCast::int($a->getMetadataArray()['slideIndex'] ?? 0) <=> ScalarCast::int($b->getMetadataArray()['slideIndex'] ?? 0);

            return $bySlideIndex !== 0 ? $bySlideIndex : ($a->getUid() ?? 0) <=> ($b->getUid() ?? 0);
        });

        return $slides;
    }

    /**
     * The first finished story slide in slide order, or null when no slide finished.
     * The result view labels the story card from it: with no finished slide there is
     * nothing generated to label.
     */
    public function getDoneStoryArtifact(): ?Artifact
    {
        foreach ($this->getStoryArtifacts() as $slide) {
            if ($slide->getStatus() === ArtifactStatus::Done->value) {
                return $slide;
            }
        }

        return null;
    }
}
