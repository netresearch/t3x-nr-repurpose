<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator;

/**
 * Produces one artifact for a job. Implementations persist their own artifact row
 * (via JobProcessingRepository) and must NOT throw for a single-artifact business
 * failure — they record a failed artifact and return false so siblings still run.
 *
 * NOTE: Plan 3 migrates this interface to take a Pipeline\GenerationContext instead of
 * the raw job row, once ingestion + analysis exist. The walking skeleton uses the row.
 */
interface ArtifactGeneratorInterface
{
    /** @param array<string, mixed> $jobRow */
    public function supports(array $jobRow): bool;

    /**
     * @param array<string, mixed> $jobRow
     * @return bool true if the artifact was produced successfully
     */
    public function generate(array $jobRow): bool;
}
