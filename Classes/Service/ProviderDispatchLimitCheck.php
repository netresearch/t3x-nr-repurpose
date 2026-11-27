<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds the nr-llm records whose longest dispatch can outlast the run lease (#238): nr-llm makes
 * up to max_retries + 1 attempts per dispatch, each bounded by the attempt timeout, and the
 * renewal middleware cannot run between them. A call through an LLM configuration uses that
 * configuration's effective timeout per attempt, any other call the provider's api_timeout.
 *
 * The worker logs the findings once per process, before its first job: the people who run the
 * workers read that log, and the extension does not depend on EXT:reports. nr-llm's records
 * are read through its own repositories, so this follows nr-llm's model rather than its
 * table layout. A record changed while the worker runs is checked after the worker's restart.
 */
final class ProviderDispatchLimitCheck
{
    /** nr-llm's AbstractProvider clamps max_retries to this range. */
    private const int MAX_RETRIES_LIMIT = 10;

    private bool $checked = false;

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly LlmConfigurationRepository $configurations,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return list<string> one line per record above Job::PROVIDER_DISPATCH_LIMIT_SECONDS */
    public function exceeding(): array
    {
        $found = [];
        foreach ($this->providers->findActive() as $provider) {
            if ($provider instanceof Provider) {
                $line = $this->line('provider', $provider->getIdentifier(), $provider, $provider->getApiTimeout());
                if ($line !== null) {
                    $found[] = $line;
                }
            }
        }

        foreach ($this->configurations->findActive() as $configuration) {
            $provider = $configuration instanceof LlmConfiguration ? $configuration->getProvider() : null;
            if ($configuration instanceof LlmConfiguration && $provider instanceof Provider) {
                $line = $this->line('configuration', $configuration->getIdentifier(), $provider, $configuration->getEffectiveTimeout());
                if ($line !== null) {
                    $found[] = $line;
                }
            }
        }

        return $found;
    }

    /** Logs the records above the limit, once per process. A failed check never fails a job. */
    public function warnOnce(): void
    {
        if ($this->checked) {
            return;
        }

        $this->checked = true;
        try {
            $found = $this->exceeding();
        } catch (Throwable $e) {
            $this->logger->warning('The nr-llm provider limits could not be checked', ['exception' => $e]);

            return;
        }

        if ($found !== []) {
            $this->logger->warning(
                sprintf(
                    'An nr-llm call can outlast the run lease: (max_retries + 1) × attempt timeout must stay at or below %d s, so a live run can lose its job to a redelivery',
                    Job::PROVIDER_DISPATCH_LIMIT_SECONDS,
                ),
                ['records' => $found],
            );
        }
    }

    private function line(string $kind, string $identifier, Provider $provider, int $attemptTimeout): ?string
    {
        $attempts = max(0, min(self::MAX_RETRIES_LIMIT, $provider->getMaxRetries())) + 1;
        $worst    = $attempts * $attemptTimeout;
        if ($worst <= Job::PROVIDER_DISPATCH_LIMIT_SECONDS) {
            return null;
        }

        return sprintf('%s "%s": %d attempts × %d s = %d s', $kind, $identifier, $attempts, $attemptTimeout, $worst);
    }
}
