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
 * The values are the ones nr-llm dispatches with: a provider's options JSON replaces its stored
 * max_retries and api_timeout (ProviderAdapterRegistry::buildAdapterConfig()), and a
 * configuration's options JSON replaces its effective timeout (LlmConfiguration::toOptionsArray()).
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

    /** AbstractProvider::configure()'s defaults for an option it cannot read as a number. */
    private const int DEFAULT_MAX_RETRIES = 3;

    private const int DEFAULT_TIMEOUT = 120;

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
                $line = $this->line('provider', $provider->getIdentifier(), $provider, $this->providerTimeout($provider));
                if ($line !== null) {
                    $found[] = $line;
                }
            }
        }

        foreach ($this->configurations->findActive() as $configuration) {
            $provider = $configuration instanceof LlmConfiguration ? $configuration->getProvider() : null;
            if ($configuration instanceof LlmConfiguration && $provider instanceof Provider) {
                $line = $this->line('configuration', $configuration->getIdentifier(), $provider, $this->configurationTimeout($configuration, $provider));
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
        $retries  = $this->option($provider->getOptionsObject()->toArray(), 'maxRetries', $provider->getMaxRetries(), self::DEFAULT_MAX_RETRIES);
        $attempts = max(0, min(self::MAX_RETRIES_LIMIT, $retries)) + 1;
        $worst    = $attempts * $attemptTimeout;
        if ($worst <= Job::PROVIDER_DISPATCH_LIMIT_SECONDS) {
            return null;
        }

        return sprintf('%s "%s": %d attempts × %d s = %d s', $kind, $identifier, $attempts, $attemptTimeout, $worst);
    }

    /** The provider's timeout as its adapter is configured with it. */
    private function providerTimeout(Provider $provider): int
    {
        return $this->option($provider->getOptionsObject()->toArray(), 'timeout', $provider->getApiTimeout(), self::DEFAULT_TIMEOUT);
    }

    /**
     * A call through a configuration carries the configuration's timeout in its options; a value
     * that is no positive number leaves the provider's timeout (AbstractProvider::resolveRequestTimeout()).
     */
    private function configurationTimeout(LlmConfiguration $configuration, Provider $provider): int
    {
        $raw = $configuration->getOptionsArray()['timeout'] ?? $configuration->getEffectiveTimeout();

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : $this->providerTimeout($provider);
    }

    /**
     * An option from the options JSON replaces the stored value; one that is no number gives
     * nr-llm's default, as ResponseParserTrait::getInt() reads it.
     *
     * @param array<array-key, mixed> $options
     */
    private function option(array $options, string $key, int $stored, int $default): int
    {
        if (!array_key_exists($key, $options)) {
            return $stored;
        }

        return is_numeric($options[$key]) ? (int) $options[$key] : $default;
    }
}
