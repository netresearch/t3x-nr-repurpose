<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Fixtures;

use RuntimeException;

/**
 * Throws when a file's metadata is updated, while a test has switched it on.
 *
 * Registered by the fixture extension `nrrepurpose_failing_metadata_fixture`.
 * The event is dispatched by MetaDataRepository::update(), which is what
 * JobFileStorage reaches when it saves the AI description of a stored file.
 */
final class FailsOnMetaDataUpdateListener
{
    public static bool $fail = false;

    public function __invoke(): void
    {
        if (self::$fail) {
            throw new RuntimeException('Metadata update failed on purpose', 1790000901);
        }
    }
}
