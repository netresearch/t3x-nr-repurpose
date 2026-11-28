<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Fixtures;

use Closure;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Exception\RunOwnershipLostException;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;

/**
 * Writes one artifact per run. A hook runs first; a lost claim it throws is kept, as a
 * generator's own catch would swallow it, so the test sees what the orchestrator does next.
 */
final class ClaimTestGenerator implements ArtifactGeneratorInterface
{
    public int $runs = 0;

    public ?SourceDocument $document = null;

    public ?RunOwnershipLostException $thrown = null;

    public function __construct(
        private readonly JobProcessingRepository $jobs,
        private readonly ?Closure $hook = null,
    ) {}

    public function supports(GenerationContext $ctx): bool
    {
        return true;
    }

    public function generate(GenerationContext $ctx): bool
    {
        ++$this->runs;
        $this->document = $ctx->document;
        if ($this->hook instanceof Closure) {
            try {
                ($this->hook)();
            } catch (RunOwnershipLostException $e) {
                $this->thrown = $e;
            }

            return true;
        }

        $ctx->progress?->step('Stub: halfway', 0.5);
        $this->jobs->insertArtifact($ctx->jobUid(), ArtifactType::Stub, 'default', 0, ArtifactStatus::Done);

        return true;
    }
}
