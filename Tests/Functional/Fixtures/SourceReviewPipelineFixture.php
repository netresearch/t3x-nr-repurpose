<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Fixtures;

use Netresearch\NrLlm\Service\Decision\DecisionRequest;
use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Service\Decision\DecisionServiceInterface;
use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfile;
use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfileRegistry;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\SourceReviewFixture;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzerInterface;
use RuntimeException;

final class SourceReviewPipelineFixture implements SourceIngestionServiceInterface, DocumentAnalyzerInterface, DecisionServiceInterface, ArtifactGeneratorInterface
{
    public int $evaluations = 0;

    public int $generations = 0;

    public function __construct(
        private readonly DecisionProfileRegistry $profiles,
    ) {}

    public function ingest(JobSnapshot $job): SourceDocument
    {
        return new SourceDocument(
            'Forecast',
            'Sales may grow.',
            'https://example.com/source',
            1,
            'en',
        );
    }

    public function analyze(
        SourceDocument $document,
        JobSnapshot $job,
    ): ContentBrief {
        return new ContentBrief(
            'Forecast',
            'Sales will grow.',
            [],
            [],
            'Investors',
            'en',
        );
    }

    public function assertAvailable(
        string $profile,
        ?string $configuration = null,
    ): void {
        if (!$this->profiles->find($profile) instanceof DecisionProfile) {
            throw new RuntimeException('Consumer profile was not registered');
        }
    }

    public function evaluate(DecisionRequest $request): DecisionResult
    {
        ++$this->evaluations;

        return SourceReviewFixture::result(false);
    }

    public function supports(GenerationContext $ctx): bool
    {
        return true;
    }

    public function generate(GenerationContext $ctx): bool
    {
        ++$this->generations;
        throw new RuntimeException('A generator ran after a rejected content brief');
    }
}
