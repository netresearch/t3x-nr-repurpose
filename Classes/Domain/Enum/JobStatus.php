<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

enum JobStatus: string
{
    case Queued = 'queued';
    case Ingesting = 'ingesting';
    case Analyzing = 'analyzing';
    case Generating = 'generating';
    case Done = 'done';
    case PartiallyDone = 'partially_done';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::PartiallyDone, self::Failed => true,
            default => false,
        };
    }

    /**
     * LLL key (locallang.xlf) for the human-readable status label.
     * `get` prefix so Fluid `{job.statusEnum.labelKey}` resolves it (Fluid
     * property access calls getX()/isX(), never a bare method).
     */
    public function getLabelKey(): string
    {
        return 'status.' . $this->value;
    }

    /** Bootstrap contextual suffix used for the status badge in the backend module. */
    public function getSeverity(): string
    {
        return match ($this) {
            self::Done => 'success',
            self::PartiallyDone => 'warning',
            self::Failed => 'danger',
            default => 'info',
        };
    }
}
