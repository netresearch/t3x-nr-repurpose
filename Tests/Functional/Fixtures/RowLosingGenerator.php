<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Fixtures;

use Netresearch\NrLlm\Testing\FakeBudgetService;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Generator\AbstractGenerator;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;

/** Inserts its row, loses it (deleted from outside), then makes its final write. */
final class RowLosingGenerator extends AbstractGenerator
{
    public function __construct(JobProcessingRepository $jobs, private readonly ConnectionPool $pool)
    {
        parent::__construct($jobs, new FakeBudgetService(), new NullLogger());
    }

    public function supports(GenerationContext $ctx): bool
    {
        return true;
    }

    public function generate(GenerationContext $ctx): bool
    {
        $artifactUid = $this->jobs->insertArtifact($ctx->jobUid(), ArtifactType::Stub, 'lost', 0, ArtifactStatus::Pending);
        $this->pool->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->delete('tx_nrrepurpose_domain_model_artifact', ['uid' => $artifactUid]);

        // The final write, as every generator makes it.
        return $this->storeArtifact($artifactUid, $ctx->jobUid(), ['status' => ArtifactStatus::Done->value]);
    }
}
