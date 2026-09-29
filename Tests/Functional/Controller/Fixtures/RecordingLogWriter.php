<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Controller\Fixtures;

use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Writer\AbstractWriter;
use TYPO3\CMS\Core\Log\Writer\WriterInterface;

/**
 * Keeps every record it is given, so a test can read what reached the log through the
 * real LogManager and writer configuration (TYPO3_CONF_VARS['LOG']).
 */
final class RecordingLogWriter extends AbstractWriter
{
    /** @var list<LogRecord> */
    public static array $records = [];

    public function writeLog(LogRecord $record): WriterInterface
    {
        self::$records[] = $record;

        return $this;
    }
}
