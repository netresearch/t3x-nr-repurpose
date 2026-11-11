<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Resource;

/**
 * Metadata JobFileStorage wants on the sys_file_metadata record of a file it is about to
 * add, keyed by the file's combined identifier ("1:/repurpose/x.png"). FAL creates that
 * record while it indexes the file, inside ResourceStorage::addFile(), so the values
 * cannot be passed in; ApplyPendingFileMetadata writes them when the record is created.
 *
 * One shared instance (DI default). JobFileStorage removes its entry in a finally block:
 * the worker is long-running, and a stale entry would be applied to a later file with
 * the same identifier.
 */
final class PendingFileMetadata
{
    /** @var array<string, array<string, string>> combined identifier => sys_file_metadata fields */
    private array $pending = [];

    /**
     * @param array<string, string> $fields
     */
    public function expect(string $combinedIdentifier, array $fields): void
    {
        $this->pending[$combinedIdentifier] = $fields;
    }

    /**
     * The fields expected for the file, removed from the list; empty when none are.
     *
     * @return array<string, string>
     */
    public function take(string $combinedIdentifier): array
    {
        $fields = $this->pending[$combinedIdentifier] ?? [];
        unset($this->pending[$combinedIdentifier]);

        return $fields;
    }

    public function forget(string $combinedIdentifier): void
    {
        unset($this->pending[$combinedIdentifier]);
    }
}
