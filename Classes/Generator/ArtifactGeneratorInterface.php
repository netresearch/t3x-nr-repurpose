<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

use Netresearch\NrRepurpose\Pipeline\GenerationContext;

/**
 * Produces one artifact for a job from the shared GenerationContext. Implementations persist
 * their own artifact row (via JobProcessingRepository) and must NOT throw for a single-artifact
 * business failure — they record a failed artifact and return false so siblings still run.
 */
interface ArtifactGeneratorInterface
{
    public function supports(GenerationContext $ctx): bool;

    /** @return bool true if the artifact was produced successfully */
    public function generate(GenerationContext $ctx): bool;
}
