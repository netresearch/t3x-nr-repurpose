<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Queue\Message;

/** Immutable. Carries only the job uid — all inputs are read from the DB by the worker. */
final readonly class GenerateArtifactsMessage
{
    public function __construct(public int $jobUid) {}
}
