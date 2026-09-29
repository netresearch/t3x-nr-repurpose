<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Every AI call goes through nr-llm's services; this extension contains no
 * provider code (AGENTS.md, "Architecture").
 *
 * Run by PHPStan through phpat (Build/phpstan.neon), not by PHPUnit.
 */
final class AiBoundaryTest
{
    public function testNoProviderSdkOrNrLlmProviderInternals(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrRepurpose'))
            ->shouldNotDependOn()
            ->classes(
                // nr-llm's own provider adapters: callers use its services
                // (Service\Feature, Specialized\*), never an adapter directly.
                Selector::inNamespace('Netresearch\NrLlm\Provider'),
                // Provider SDKs (openai-php/client, anthropic, gemini, Bedrock).
                Selector::inNamespace('OpenAI'),
                Selector::inNamespace('Anthropic'),
                Selector::inNamespace('Gemini'),
                Selector::inNamespace('Aws\BedrockRuntime'),
            )
            ->because('All AI calls go through nr-llm; nr_repurpose contains zero provider code.');
    }
}
