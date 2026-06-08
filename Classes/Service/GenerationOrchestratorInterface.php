<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

interface GenerationOrchestratorInterface
{
    public function process(int $jobUid): void;
}
