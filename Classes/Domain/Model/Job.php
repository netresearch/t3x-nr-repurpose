<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Model;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\Enum\PdfMode;
use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Domain\ValueObject\ArtifactTypeSummary;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

class Job extends AbstractEntity
{
    protected string $sourceType = 'url';
    protected string $sourceValue = '';
    protected string $theme = 'nr';
    protected string $pdfMode = 'auto';
    protected bool $wantPodcast = true;
    protected bool $wantSchaubild = true;
    protected bool $wantStory = true;
    protected string $status = 'queued';
    protected int $progress = 0;
    protected string $currentStep = '';
    protected string $errorMessage = '';
    protected string $languageDetected = '';
    protected int $beUser = 0;

    /** @var ObjectStorage<Artifact> */
    protected ObjectStorage $artifacts;

    /** @var ObjectStorage<\TYPO3\CMS\Extbase\Domain\Model\FileReference> */
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

    /** @return ObjectStorage<\TYPO3\CMS\Extbase\Domain\Model\FileReference> */
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
     * in enum order. `get` prefix so Fluid `{job.artifactTypeSummaries}`
     * resolves it.
     *
     * @return list<ArtifactTypeSummary>
     */
    public function getArtifactTypeSummaries(): array
    {
        $statusesByType = [];
        foreach ($this->artifacts as $artifact) {
            // tryFrom: a corrupted/legacy status string in a single row must not
            // throw and take down the whole job list view — skip that artifact.
            $status = ArtifactStatus::tryFrom($artifact->getStatus());
            if ($status === null) {
                continue;
            }
            $statusesByType[$artifact->getType()][] = $status;
        }

        $summaries = [];
        foreach (ArtifactType::cases() as $type) {
            if (isset($statusesByType[$type->value])) {
                $summaries[] = ArtifactTypeSummary::fromStatuses($type, $statusesByType[$type->value]);
            }
        }

        return $summaries;
    }
}
