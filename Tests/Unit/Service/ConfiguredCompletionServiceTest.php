<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrRepurpose\Service\CallerSource;
use Netresearch\NrRepurpose\Service\ConfiguredCompletionService;
use Netresearch\NrRepurpose\Service\GuardrailHintCapture;
use Netresearch\NrRepurpose\Service\UnusableAnswerException;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\FakeGuardrailHintCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ConfiguredCompletionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        FakeGuardrailHintCollector::reset();
        parent::tearDown();
    }

    /** The service with the stand-in for nr-llm's hint collector, on the configuration path. */
    private function hintingSubject(CompletionServiceInterface $inner): ConfiguredCompletionService
    {
        $repository = $this->createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($this->activeConfigurationStub());

        return new ConfiguredCompletionService($inner, new ConfigurationResolver($repository), new NullLogger(), new GuardrailHintCapture(FakeGuardrailHintCollector::class));
    }

    public function testAReturnedCallReportsTheHintsOnItsText(): void
    {
        $inner = $this->createStub(CompletionServiceInterface::class);
        $inner->method('completeMarkdownForConfiguration')->willReturnCallback(static function (): string {
            FakeGuardrailHintCollector::record(FakeGuardrailHintCollector::hint('violation', 'Breaks "No discounts"'));

            return 'text';
        });
        $subject = $this->hintingSubject($inner);

        self::assertSame([], $subject->lastGuardrailHints());
        self::assertSame('text', $subject->completeMarkdown('prompt'));
        self::assertSame([['kind' => 'violation', 'message' => 'Breaks "No discounts"']], $subject->lastGuardrailHints());
    }

    public function testTheStructuredAnswerReportsItsHints(): void
    {
        $answer                   = new FakeCompletionService();
        $answer->structuredResult = ['summary' => 'text'];

        $inner = $this->createStub(CompletionServiceInterface::class);
        $inner->method('completeStructuredForConfiguration')->willReturnCallback(static function () use ($answer): mixed {
            FakeGuardrailHintCollector::record(FakeGuardrailHintCollector::hint('unavailable', 'Not checked'));

            return $answer->completeStructured('prompt', []);
        });
        $subject = $this->hintingSubject($inner);

        self::assertSame(['summary' => 'text'], $subject->completeStructured('prompt', []));
        self::assertSame([['kind' => 'unavailable', 'message' => 'Not checked']], $subject->lastGuardrailHints());
    }

    /** A call that threw delivered no text, so the hints of the call before it are gone. */
    public function testACallThatThrowsLeavesNoHints(): void
    {
        $calls = 0;
        $inner = $this->createStub(CompletionServiceInterface::class);
        $inner->method('completeMarkdownForConfiguration')->willReturnCallback(static function () use (&$calls): string {
            FakeGuardrailHintCollector::record(FakeGuardrailHintCollector::hint('violation', 'call ' . ++$calls));
            if ($calls === 2) {
                throw new ProviderException('down', 1);
            }

            return 'text';
        });
        $subject = $this->hintingSubject($inner);
        $subject->completeMarkdown('prompt');

        try {
            $subject->completeMarkdown('prompt');
            self::fail('The failure must reach the caller');
        } catch (ProviderException $e) {
            self::assertSame(1, $e->getCode());
        }

        self::assertSame([], $subject->lastGuardrailHints());
    }

    /** The first answer was discarded: what the judge said about it describes no stored text. */
    public function testOnlyTheAttemptThatReturnedReportsItsHints(): void
    {
        $calls = 0;
        $inner = $this->createStub(CompletionServiceInterface::class);
        $inner->method('completeJsonForConfiguration')->willReturnCallback(static function () use (&$calls): array {
            FakeGuardrailHintCollector::record(FakeGuardrailHintCollector::hint('violation', 'attempt ' . ++$calls));
            if ($calls === 1) {
                throw new ProviderException('no text', 2041730951);
            }

            return ['answer' => 'second try'];
        });

        $subject = $this->hintingSubject($inner);

        self::assertSame(['answer' => 'second try'], $subject->completeJson('prompt'));
        self::assertSame([['kind' => 'violation', 'message' => 'attempt 2']], $subject->lastGuardrailHints());
    }

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

    /** @return array<string, array{0: int, 1: ?int}> limit on the record, limit of the call */
    public static function recordLimits(): array
    {
        return [
            'the default of a record'   => [1000, ConfiguredCompletionService::MIN_OUTPUT_TOKENS],
            'just below the least room' => [15999, ConfiguredCompletionService::MIN_OUTPUT_TOKENS],
            'at the least room'         => [16000, null],
            'more than that'            => [32000, null],
            'unset: the model decides'  => [0, null],
        ];
    }

    #[DataProvider('recordLimits')]
    public function testEveryCompletionGetsTheRoomOfATextThatLong(int $recordLimit, ?int $expected): void
    {
        $configuration = $this->activeConfigurationStub();
        self::assertInstanceOf(Stub::class, $configuration);
        $configuration->method('getMaxTokens')->willReturn($recordLimit);
        $inner            = new FakeCompletionService();
        $inner->responses = [];

        $subject = $this->subject($inner, $configuration);

        $subject->completeJson('json');
        $subject->completeStructured('structured', ['type' => 'object']);
        $subject->completeMarkdown('markdown');

        self::assertSame($expected, $inner->completeJsonForConfigurationCalls[0]['options']?->getMaxTokens());
        self::assertSame($expected, $inner->completeStructuredForConfigurationCalls[0]['options']?->getMaxTokens());
        self::assertSame($expected, $inner->completeMarkdownForConfigurationCalls[0]['options']?->getMaxTokens());
    }

    public function testACallThatNamesItsLimitKeepsIt(): void
    {
        $inner = new FakeCompletionService();

        $this->subject($inner, $this->activeConfigurationStub())->completeJson('prompt', (new ChatOptions())->withMaxTokens(500));

        self::assertSame(500, $inner->completeJsonForConfigurationCalls[0]['options']?->getMaxTokens());
    }

    public function testNoTextAtTheLimitIsAskedAgainWithTwiceTheRoom(): void
    {
        $configuration = $this->activeConfigurationStub();
        self::assertInstanceOf(Stub::class, $configuration);
        $configuration->method('getMaxTokens')->willReturn(16000);
        $inner             = new FakeCompletionService();
        $inner->throwable  = new ProviderException('no text', 2041730951);
        $inner->jsonResult = ['answer' => 'second try'];

        $answer = $this->subject($inner, $configuration)->completeJson('prompt');

        self::assertSame(['answer' => 'second try'], $answer);
        self::assertCount(2, $inner->completeJsonForConfigurationCalls);
        self::assertSame(32000, $inner->completeJsonForConfigurationCalls[1]['options']?->getMaxTokens());
    }

    public function testNoTextAtTheLimitTwiceIsAnUnusableAnswer(): void
    {
        $repository = $this->createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($this->activeConfigurationStub());
        $failure = new ProviderException('no text', 2041730951);
        $inner   = $this->createStub(CompletionServiceInterface::class);
        $inner->method('completeJsonForConfiguration')->willThrowException($failure);

        $subject = new ConfiguredCompletionService($inner, new ConfigurationResolver($repository), new NullLogger());

        try {
            $subject->completeJson('prompt');
            self::fail('Two answers without text are no answer');
        } catch (UnusableAnswerException $e) {
            self::assertSame($failure, $e->getPrevious());
        }
    }

    public function testAnotherProviderFailureIsNotAskedAgain(): void
    {
        $inner            = new FakeCompletionService();
        $inner->throwable = new ProviderException('down', 1);

        try {
            $this->subject($inner, $this->activeConfigurationStub())->completeJson('prompt');
            self::fail('The failure must reach the caller');
        } catch (ProviderException $e) {
            self::assertSame(1, $e->getCode());
        }

        self::assertCount(1, $inner->completeJsonForConfigurationCalls);
    }

    public function testTheInstanceDefaultGetsTheRoomToo(): void
    {
        $inner = new FakeCompletionService();

        $this->subject($inner, null)->completeJson('prompt');

        self::assertSame(ConfiguredCompletionService::MIN_OUTPUT_TOKENS, $inner->completeJsonCalls[0]['options']?->getMaxTokens());
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
