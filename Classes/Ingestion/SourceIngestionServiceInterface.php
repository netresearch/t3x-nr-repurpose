<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;

interface SourceIngestionServiceInterface
{
    /**
     * @param array<string,mixed> $jobRow
     * @throws IngestionException on an unreachable/unreadable source
     */
    public function ingest(array $jobRow): SourceDocument;
}
