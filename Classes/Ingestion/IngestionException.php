<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

/**
 * Thrown when a job source cannot be turned into a SourceDocument: the URL is
 * unreachable, the PDF is empty/encrypted/unreadable, or no usable text could be
 * extracted by any tier. The orchestrator (Plan 3) catches this and marks the job failed.
 */
final class IngestionException extends \RuntimeException {}
