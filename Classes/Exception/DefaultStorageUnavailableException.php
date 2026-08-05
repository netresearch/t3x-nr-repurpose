<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Exception;

use RuntimeException;

/**
 * Raised when no default FAL storage is configured/available to store generated artifacts.
 * Extends RuntimeException so existing catch contracts keep working.
 */
final class DefaultStorageUnavailableException extends RuntimeException {}
