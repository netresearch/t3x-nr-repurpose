<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrRepurpose\Tests\Unit\Fixture\FakeGuardrailHintCollector;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// A stand-in under the service id of nr-llm's collector (nr-llm ADR-288), only while the
// installed nr-llm lacks it. An nr-llm that has it injects the collector as a typed
// argument into its own services, which the stand-in could not satisfy, so its own
// service is used then.
return static function (ContainerConfigurator $container): void {
    $id = 'Netresearch\NrLlm\Service\Guardrail\GuardrailHintCollector';
    if (class_exists($id)) {
        return;
    }

    $container->services()->set($id, FakeGuardrailHintCollector::class)->public()->share();
};
