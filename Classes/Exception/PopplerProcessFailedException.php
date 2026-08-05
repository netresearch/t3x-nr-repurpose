<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Exception;

use RuntimeException;

/**
 * Raised when a Poppler binary (pdftoppm/pdftotext) exits non-zero or times out.
 * Extends RuntimeException so existing catch contracts (IngestionException aside,
 * callers catch Throwable/RuntimeException) keep working.
 */
final class PopplerProcessFailedException extends RuntimeException {}
