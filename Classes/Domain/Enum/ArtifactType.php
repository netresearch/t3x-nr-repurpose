<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

enum ArtifactType: string
{
    case Podcast = 'podcast';
    case Schaubild = 'schaubild';
    case Story = 'story';
    case Stub = 'stub';
}
