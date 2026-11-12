<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Netresearch\NrRepurpose\Service\ConfiguredCompletionService;
use Netresearch\NrRepurpose\Service\GuardrailHintCapture;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\FakeGuardrailHintCollector;
use ReflectionProperty;

/**
 * The container hands nr-llm's guardrail-hint collector to the completion service:
 * Configuration/Services.yaml injects it into GuardrailHintCapture as an optional
 * service, and the completion service gets that capture. While the installed nr-llm
 * lacks the collector, a fixture extension registers a stand-in under its service id.
 */
final class GuardrailHintCaptureWiringTest extends AbstractFunctionalTestCase
{
    private const string COLLECTOR = 'Netresearch\NrLlm\Service\Guardrail\GuardrailHintCollector';

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_repurpose/Tests/Functional/Fixtures/Extensions/nrrepurpose_hint_collector_fixture',
        'netresearch/nr-repurpose',
    ];

    protected function tearDown(): void
    {
        FakeGuardrailHintCollector::reset();
        parent::tearDown();
    }

    public function testTheCompletionServiceCapturesThroughTheCollectorService(): void
    {
        $capture = (new ReflectionProperty(ConfiguredCompletionService::class, 'hintCapture'))
            ->getValue($this->get(ConfiguredCompletionService::class));
        self::assertInstanceOf(GuardrailHintCapture::class, $capture);

        $collector = (new ReflectionProperty(GuardrailHintCapture::class, 'collector'))->getValue($capture);
        self::assertNotNull($collector, 'The collector service is injected.');
        self::assertSame($this->get(self::COLLECTOR), $collector, 'It is the shared instance nr-llm records into.');
    }
}
