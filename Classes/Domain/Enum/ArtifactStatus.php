<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

enum ArtifactStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';

    /**
     * LLL key (locallang.xlf) for the human-readable status label.
     * `get` prefix so Fluid `{artifact.statusEnum.labelKey}` resolves it.
     */
    public function getLabelKey(): string
    {
        return 'artifactStatus.' . $this->value;
    }

    /** Bootstrap contextual suffix used for the artifact status badge. */
    public function getSeverity(): string
    {
        return match ($this) {
            self::Done => 'success',
            self::Failed => 'danger',
            self::Pending => 'warning',
        };
    }
}
