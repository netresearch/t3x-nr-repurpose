<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Pipeline;

use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;

/**
 * Renews the generation run's claim before every provider call (#238). Every call this
 * extension makes goes through nr-llm's middleware pipeline: completions, structured output and
 * its repair round, vision, decisions (LlmServiceManager), speech and images
 * (AbstractSpecializedService). So a live run never spends more than one provider call without
 * renewing its claim, wherever the call is made.
 *
 * Innermost layer: it runs once per dispatch, also for every attempt of the fallback chain, and
 * not for a cache hit, which reaches no provider. Outside a generation run this process holds
 * no claim and the renewal does nothing. A lost claim throws RunOwnershipLostException; nr-llm
 * classifies it as neither retryable nor tripping, so it reaches the run unchanged.
 */
final readonly class RunClaimRenewalMiddleware implements ProviderMiddlewareInterface
{
    public function __construct(private RunHeartbeatInterface $heartbeat) {}

    /** Below every nr-llm middleware (CircuitBreaker 20 is the lowest): directly before the call. */
    public static function getDefaultPriority(): int
    {
        return -1000;
    }

    public function handle(ProviderCallContext $context, callable $next): mixed
    {
        $this->heartbeat->renewHeld();

        return $next($context);
    }
}
