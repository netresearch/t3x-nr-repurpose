<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Model;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
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
    protected string $metadata = '';

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

    public function getMetadata(): string
    {
        return $this->metadata;
    }
}
