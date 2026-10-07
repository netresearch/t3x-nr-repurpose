<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrRepurpose\Service\CallerSource;
use Netresearch\NrRepurpose\Service\ConfiguredCompletionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ConfiguredCompletionServiceTest extends TestCase
{
    private function activeConfigurationStub(): LlmConfiguration
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('hasAccessRestrictions')->willReturn(false);

        return $configuration;
    }

    /**
     * @param LlmConfiguration|null $found what the repository returns for the identifier
     */
    private function subject(FakeCompletionService $inner, ?LlmConfiguration $found): ConfiguredCompletionService
    {
        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($found);

        return new ConfiguredCompletionService($inner, new ConfigurationResolver($repository), new NullLogger());
    }

    public function testRoutesCompleteJsonToTheNamedConfigurationWhenResolvable(): void
    {
        $configuration     = $this->activeConfigurationStub();
        $inner             = new FakeCompletionService();
        $inner->jsonResult = ['ok' => true];

        $result = $this->subject($inner, $configuration)->completeJson('prompt');

        self::assertSame(['ok' => true], $result);
        // Routed through the configuration path, not the instance-default one.
        self::assertCount(1, $inner->completeJsonForConfigurationCalls);
        self::assertSame($configuration, $inner->completeJsonForConfigurationCalls[0]['configuration']);
        self::assertSame([], $inner->completeJsonCalls);
    }

    public function testFallsBackToInstanceDefaultWhenConfigurationIsMissing(): void
    {
        $inner             = new FakeCompletionService();
        $inner->jsonResult = ['fallback' => true];

        // Repository returns null -> getActiveByIdentifier throws -> fail-soft fallback.
        $result = $this->subject($inner, null)->completeJson('prompt');

        self::assertSame(['fallback' => true], $result);
        self::assertCount(1, $inner->completeJsonCalls);
        self::assertSame([], $inner->completeJsonForConfigurationCalls);
    }

    public function testFallsBackWhenConfigurationIsInactive(): void
    {
        $inactive = self::createStub(LlmConfiguration::class);
        $inactive->method('isActive')->willReturn(false);

        $inner = new FakeCompletionService();

        $this->subject($inner, $inactive)->completeJson('prompt');

        self::assertCount(1, $inner->completeJsonCalls);
        self::assertSame([], $inner->completeJsonForConfigurationCalls);
    }

    public function testRoutesMarkdownToTheConfigurationPath(): void
    {
        $configuration         = $this->activeConfigurationStub();
        $inner                 = new FakeCompletionService();
        $inner->markdownResult = '# heading';

        self::assertSame('# heading', $this->subject($inner, $configuration)->completeMarkdown('p'));

        self::assertCount(1, $inner->completeMarkdownForConfigurationCalls);
        self::assertSame([], $inner->completeMarkdownCalls);
    }

    public function testFallsBackToTheInstanceDefaultForMarkdown(): void
    {
        $inner                 = new FakeCompletionService();
        $inner->markdownResult = '# heading';

        self::assertSame('# heading', $this->subject($inner, null)->completeMarkdown('p'));

        self::assertCount(1, $inner->completeMarkdownCalls);
        self::assertSame([], $inner->completeMarkdownForConfigurationCalls);
    }

    /**
     * nr-llm 0.38 returns the decoded answer of a structured completion as an
     * array, 0.39 wraps it in a StructuredCompletionResponse (ADR-211). nr-llm's
     * own fake returns whichever shape the installed version declares, so this
     * test runs against the real shape of both versions.
     */
    public function testReturnsTheDecodedStructuredAnswerOnTheConfigurationPath(): void
    {
        $configuration           = $this->activeConfigurationStub();
        $inner                   = new FakeCompletionService();
        $inner->structuredResult = ['faq' => [['question' => 'Q?', 'answer' => 'A.']]];

        $schema = ['type' => 'object'];

        $result = $this->subject($inner, $configuration)->completeStructured('prompt', $schema);

        self::assertSame(['faq' => [['question' => 'Q?', 'answer' => 'A.']]], $result);
        self::assertCount(1, $inner->completeStructuredForConfigurationCalls);
        self::assertSame($configuration, $inner->completeStructuredForConfigurationCalls[0]['configuration']);
        self::assertSame($schema, $inner->completeStructuredForConfigurationCalls[0]['schema']);
        self::assertSame('nr_repurpose', $inner->completeStructuredForConfigurationCalls[0]['options']?->getCallerSourceExtension());
        self::assertSame([], $inner->completeStructuredCalls);
    }

    public function testReturnsTheDecodedStructuredAnswerOnTheFallbackPath(): void
    {
        $inner                   = new FakeCompletionService();
        $inner->structuredResult = ['summary' => 'Revenue grew.'];

        $result = $this->subject($inner, null)->completeStructured('prompt', ['type' => 'object']);

        self::assertSame(['summary' => 'Revenue grew.'], $result);
        self::assertCount(1, $inner->completeStructuredCalls);
        self::assertSame([], $inner->completeStructuredForConfigurationCalls);
    }

    public function testResolvesTheConfigurationOnlyOncePerInstance(): void
    {
        $repository = $this->createMock(LlmConfigurationRepository::class);
        // Memoization: the repository must be consulted exactly once across two calls.
        $repository->expects(self::once())
            ->method('findOneByIdentifier')
            ->willReturn($this->activeConfigurationStub());

        $inner             = new FakeCompletionService();
        $inner->jsonResult = [];

        $subject = new ConfiguredCompletionService($inner, new ConfigurationResolver($repository), new NullLogger());

        $subject->completeJson('a');
        $subject->completeJson('b');
    }

    /**
     * This service is the funnel for every text completion in this extension, so a
     * call that names no caller still reaches nr-llm attributed to the extension
     * rather than landing in the Analytics "Unattributed" bucket.
     */
    public function testStampsTheExtensionKeyOnOptionsThatNameNoCaller(): void
    {
        $inner             = new FakeCompletionService();
        $inner->jsonResult = [];

        $this->subject($inner, $this->activeConfigurationStub())->completeJson('p', new ChatOptions(temperature: 0.4));

        $options = $inner->completeJsonForConfigurationCalls[0]['options'];
        self::assertInstanceOf(ChatOptions::class, $options);
        self::assertSame('nr_repurpose', $options->getCallerSourceExtension());
        self::assertSame('', $options->getCallerSourceOperation());
        self::assertSame(0.4, $options->getTemperature());
    }

    public function testStampsTheExtensionKeyWhenTheCallerPassesNoOptions(): void
    {
        $inner             = new FakeCompletionService();
        $inner->jsonResult = [];

        $this->subject($inner, null)->completeJson('p');

        $options = $inner->completeJsonCalls[0]['options'];
        self::assertInstanceOf(ChatOptions::class, $options);
        self::assertSame('nr_repurpose', $options->getCallerSourceExtension());
    }

    /**
     * The operation identifies the pipeline step and only the call site knows it —
     * this service must never overwrite what a caller already set.
     */
    public function testKeepsTheOperationTheCallSiteSet(): void
    {
        $inner             = new FakeCompletionService();
        $inner->jsonResult = [];

        $annotated = (new ChatOptions())->withCallerSource(CallerSource::EXTENSION, CallerSource::GENERATE_STORY);
        $this->subject($inner, $this->activeConfigurationStub())->completeJson('p', $annotated);

        $options = $inner->completeJsonForConfigurationCalls[0]['options'];
        self::assertInstanceOf(ChatOptions::class, $options);
        self::assertSame(CallerSource::GENERATE_STORY, $options->getCallerSourceOperation());
    }
}
