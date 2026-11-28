<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Closure;
use Netresearch\NrLlm\Specialized\AbstractSpecializedService;
use Netresearch\NrVault\Service\VaultServiceInterface;
use ReflectionClass;

/**
 * An nr-llm specialized service (final, not mockable) whose first step asks the given vault:
 * its availability check reads the vault, so a vault double that throws stands in for the
 * lost claim a takeover raises inside nr-llm's pipeline (#238). Only the two properties that
 * check reads are set, and the optional repositories the model lookup reads are null.
 */
final class LosingSpecializedService
{
    /**
     * @template T of AbstractSpecializedService
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function of(string $class, VaultServiceInterface $vault): object
    {
        $service = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        Closure::bind(static function (AbstractSpecializedService $s) use ($vault): void {
            $s->vault                   = $vault;
            $s->apiKeyIdentifier        = 'nr_repurpose_test';
            $s->modelRepository         = null;
            $s->configurationRepository = null;
        }, null, AbstractSpecializedService::class)($service);

        return $service;
    }
}
