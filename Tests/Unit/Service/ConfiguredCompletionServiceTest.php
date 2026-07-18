<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Testing\FakeCompletionService;
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
        $configuration = $this->activeConfigurationStub();
        $inner = new FakeCompletionService();
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
        $inner = new FakeCompletionService();
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
        $inner->responses = [$this->response()];

        $this->subject($inner, $inactive)->complete('prompt');

        self::assertCount(1, $inner->completeCalls);
        self::assertSame([], $inner->completeForConfigurationCalls);
    }

    public function testRoutesMarkdownAndCompleteToTheConfigurationPath(): void
    {
        $configuration = $this->activeConfigurationStub();
        $inner = new FakeCompletionService();
        $inner->markdownResult = '# heading';
        $inner->responses = [$this->response()];

        $subject = $this->subject($inner, $configuration);

        self::assertSame('# heading', $subject->completeMarkdown('p'));
        $subject->complete('p');

        self::assertCount(1, $inner->completeMarkdownForConfigurationCalls);
        self::assertCount(1, $inner->completeForConfigurationCalls);
    }

    public function testResolvesTheConfigurationOnlyOncePerInstance(): void
    {
        $repository = $this->createMock(LlmConfigurationRepository::class);
        // Memoization: the repository must be consulted exactly once across two calls.
        $repository->expects(self::once())
            ->method('findOneByIdentifier')
            ->willReturn($this->activeConfigurationStub());

        $inner = new FakeCompletionService();
        $inner->jsonResult = [];
        $subject = new ConfiguredCompletionService($inner, new ConfigurationResolver($repository), new NullLogger());

        $subject->completeJson('a');
        $subject->completeJson('b');
    }

    public function testForConfigurationMethodsPassStraightThrough(): void
    {
        $explicit = self::createStub(LlmConfiguration::class);
        $inner = new FakeCompletionService();
        $inner->jsonResult = ['explicit' => true];

        // No resolver interaction needed: the caller already named a configuration.
        $subject = $this->subject($inner, null);
        $result = $subject->completeJsonForConfiguration('p', $explicit);

        self::assertSame(['explicit' => true], $result);
        self::assertSame($explicit, $inner->completeJsonForConfigurationCalls[0]['configuration']);
    }

    private function response(): CompletionResponse
    {
        return new CompletionResponse('text', 'fake-model', new UsageStatistics(0, 0, 0));
    }
}
