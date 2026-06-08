<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

enum ArtifactStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
}
