<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Model;

use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

class Job extends AbstractEntity
{
    protected string $sourceType = 'url';
    protected string $sourceValue = '';
    protected string $theme = 'nr';
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

    public function __construct()
    {
        $this->artifacts = new ObjectStorage();
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
}
