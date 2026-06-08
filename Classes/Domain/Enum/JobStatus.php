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
}
