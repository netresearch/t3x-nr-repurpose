<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Exception;

use RuntimeException;

/**
 * Raised when a Poppler invocation succeeds but produces no usable output
 * (e.g. pdftoppm exits 0 without writing the expected PNG).
 * Extends RuntimeException so existing catch contracts keep working.
 */
final class PopplerEmptyOutputException extends RuntimeException {}
