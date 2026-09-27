<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Controller\Fixtures;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A DBAL driver middleware that records every executed SQL statement, registered through
 * TYPO3_CONF_VARS['DB']['Connections']['Default']['driverMiddlewares']. DBAL's logging
 * middleware does the wrapping; ConnectionPool builds the target without arguments, so the
 * recording lives in a static list.
 *
 * DBAL logs a prepared statement twice (at prepare and at execute); only "Executing query"
 * and the execute line of a statement ("… (parameters: …") count, so each run counts once.
 */
final class QueryCountingMiddleware implements Middleware
{
    /** @var list<string> */
    public static array $queries = [];

    public function wrap(Driver $driver): Driver
    {
        $logger = new class extends AbstractLogger {
            /** @param array<array-key, mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $message = (string) $message;
                if (str_starts_with($message, 'Executing query:') || str_contains($message, '(parameters:')) {
                    QueryCountingMiddleware::$queries[] = is_string($context['sql'] ?? null) ? $context['sql'] : $message;
                }
            }
        };

        return (new LoggingMiddleware($logger))->wrap($driver);
    }
}
