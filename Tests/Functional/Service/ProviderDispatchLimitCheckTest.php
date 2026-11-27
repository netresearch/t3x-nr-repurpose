<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Queue\Handler\GenerateArtifactsHandler;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Netresearch\NrRepurpose\Service\ProviderDispatchLimitCheck;
use Netresearch\NrRepurpose\Service\RunOutcome;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingMessageBus;
use Psr\Log\NullLogger;
use ReflectionProperty;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The nr-llm records whose longest dispatch can outlast the run lease (#238): up to
 * max_retries + 1 attempts, each bounded by the configuration's effective timeout for a call
 * through it, else by the provider's api_timeout.
 */
final class ProviderDispatchLimitCheckTest extends AbstractFunctionalTestCase
{
    public function testItNamesEveryRecordAboveTheLimitAndNoOther(): void
    {
        $fits    = $this->provider('fits', 3, 300);         // 4 × 300 = 1200
        $tooLong = $this->provider('too-long', 10, 600);    // 11 × 600 = 6600
        $this->provider('inactive', 10, 600, active: false);
        $this->configuration('long-timeout', $this->model($fits), 400);   // 4 × 400 = 1600
        $this->configuration('own-timeout-fits', $this->model($tooLong), 100); // 11 × 100 = 1100

        $found = $this->get(ProviderDispatchLimitCheck::class)->exceeding();

        self::assertSame([
            'provider "too-long": 11 attempts × 600 s = 6600 s',
            'configuration "long-timeout": 4 attempts × 400 s = 1600 s',
        ], $found);
    }

    public function testTheWorkerWarnsOnceBeforeItsFirstJob(): void
    {
        $this->provider('too-long', 10, 600);
        $logger = new RecordingLogger();
        $check  = new ProviderDispatchLimitCheck(
            $this->get(ProviderRepository::class),
            $this->get(LlmConfigurationRepository::class),
            $logger,
        );

        $check->warnOnce();
        $check->warnOnce();

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(['provider "too-long": 11 attempts × 600 s = 6600 s'], $logger->records[0]['context']['records'] ?? null);
    }

    /** The handler runs the check before its job: one warning for the first of two messages. */
    public function testTheHandlerChecksBeforeItsFirstJob(): void
    {
        $this->provider('too-long', 10, 600);
        $logger       = new RecordingLogger();
        $check        = new ProviderDispatchLimitCheck($this->get(ProviderRepository::class), $this->get(LlmConfigurationRepository::class), $logger);
        $orchestrator = new readonly class implements GenerationOrchestratorInterface {
            public function process(int $jobUid): RunOutcome
            {
                return RunOutcome::NotFound;
            }
        };
        $handler = new GenerateArtifactsHandler($orchestrator, $this->get(JobProcessingRepository::class), new NullLogger(), new RecordingMessageBus(), $check);

        $handler(new GenerateArtifactsMessage(1));
        $handler(new GenerateArtifactsMessage(2));

        self::assertCount(1, $logger->records);
    }

    /** The container gives the worker's handler the check. */
    public function testTheHandlerRunsTheCheck(): void
    {
        self::assertInstanceOf(
            ProviderDispatchLimitCheck::class,
            (new ReflectionProperty(GenerateArtifactsHandler::class, 'providerLimit'))->getValue($this->get(GenerateArtifactsHandler::class)),
        );
    }

    private function provider(string $identifier, int $maxRetries, int $apiTimeout, bool $active = true): int
    {
        return $this->insert('tx_nrllm_provider', [
            'identifier'   => $identifier,
            'name'         => $identifier,
            'adapter_type' => 'openai',
            'max_retries'  => $maxRetries,
            'api_timeout'  => $apiTimeout,
            'is_active'    => $active ? 1 : 0,
        ]);
    }

    private function model(int $providerUid): int
    {
        return $this->insert('tx_nrllm_model', [
            'identifier'   => 'model-' . $providerUid,
            'name'         => 'model-' . $providerUid,
            'model_id'     => 'gpt-test',
            'provider_uid' => $providerUid,
            'is_active'    => 1,
        ]);
    }

    private function configuration(string $identifier, int $modelUid, int $timeout): void
    {
        $this->insert('tx_nrllm_configuration', [
            'identifier' => $identifier,
            'name'       => $identifier,
            'model_uid'  => $modelUid,
            'timeout'    => $timeout,
            'is_active'  => 1,
        ]);
    }

    /** @param array<string, int|string> $row */
    private function insert(string $table, array $row): int
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable($table);
        $connection->insert($table, $row + ['pid' => 0]);

        return (int) $connection->lastInsertId();
    }
}
