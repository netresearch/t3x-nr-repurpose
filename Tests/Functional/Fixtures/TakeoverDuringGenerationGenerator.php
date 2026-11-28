<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Fixtures;

use Closure;
use Netresearch\NrLlm\Testing\FakeBudgetService;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Generator\AbstractGenerator;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Psr\Log\LoggerInterface;
use Throwable;

/** Inserts its row, is taken over in its provider call, and handles that like the generators. */
final class TakeoverDuringGenerationGenerator extends AbstractGenerator
{
    public function __construct(JobProcessingRepository $jobs, LoggerInterface $logger, private readonly Closure $takeover)
    {
        parent::__construct($jobs, new FakeBudgetService(), $logger);
    }

    public function supports(GenerationContext $ctx): bool
    {
        return true;
    }

    public function generate(GenerationContext $ctx): bool
    {
        $artifactUid = $this->jobs->insertArtifact($ctx->jobUid(), ArtifactType::Stub, 'taken', 0, ArtifactStatus::Pending);
        try {
            ($this->takeover)();
            // The next write of this run, as a progress step or a renewal before a call makes it.
            $ctx->progress?->step('Stub: working', 0.5);

            return $this->storeArtifact($artifactUid, $ctx->jobUid(), ['status' => ArtifactStatus::Done->value]);
        } catch (Throwable $e) {
            $this->failArtifactFrom($artifactUid, $ctx->jobUid(), 'Stub generation', $e);

            return false;
        }
    }
}
