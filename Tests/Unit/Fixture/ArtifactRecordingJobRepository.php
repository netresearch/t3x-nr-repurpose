<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;

/**
 * Records artifact inserts and the merged updates per row in memory, so the text-generator
 * tests can read back exactly what would be stored — without a database. Not final: a test
 * may override one write to simulate a storage failure.
 */
class ArtifactRecordingJobRepository extends JobProcessingRepository
{
    /** @var array<int, array{type: string, variant: string, status: string}> */
    public array $inserted = [];

    /** @var array<int, array<string, mixed>> */
    public array $updates = [];

    private int $nextUid = 500;

    public function __construct()
    {
        // Intentionally empty: bypasses the parent's ConnectionPool wiring.
    }

    public function insertArtifact(int $jobUid, ArtifactType $type, string $variant, int $fileUid, ArtifactStatus $status, ?string $error = null): int
    {
        $uid                  = $this->nextUid++;
        $this->inserted[$uid] = ['type' => $type->value, 'variant' => $variant, 'status' => $status->value];

        return $uid;
    }

    public function updateArtifact(int $artifactUid, array $fields): void
    {
        $this->updates[$artifactUid] = array_merge($this->updates[$artifactUid] ?? [], $fields);
    }

    /**
     * The stored row for one variant: the insert merged with every update to it.
     *
     * @return array<string, mixed>
     */
    public function row(string $variant = 'default'): array
    {
        foreach ($this->inserted as $uid => $insert) {
            if ($insert['variant'] === $variant) {
                return array_merge($insert, $this->updates[$uid] ?? []);
            }
        }

        return [];
    }

    /**
     * The decoded metadata of one variant's row.
     *
     * @return array<string, mixed>
     */
    public function metadata(string $variant = 'default'): array
    {
        $decoded = json_decode((string) ($this->row($variant)['metadata'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }
}
