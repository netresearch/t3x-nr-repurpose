<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Understanding;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Understanding\AnalysisException;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * In-file fake implementing the public nr-llm interface. Records calls and replays scripted
 * decoded JSON, so no real provider is ever hit.
 */
final class FakeCompletionService implements CompletionServiceInterface
{
    /** @var list<array{prompt:string, options:?ChatOptions}> */
    public array $jsonCalls = [];

    /** @param list<array<string,mixed>> $jsonResults FIFO queue of decoded arrays to return */
    public function __construct(private array $jsonResults) {}

    public function completeJson(string $prompt, ?ChatOptions $options = null): array
    {
        $this->jsonCalls[] = ['prompt' => $prompt, 'options' => $options];
        if ($this->jsonResults === []) {
            throw new \LogicException('FakeCompletionService ran out of scripted results');
        }

        return array_shift($this->jsonResults);
    }

    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        throw new \BadMethodCallException('not used in this test');
    }

    public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string
    {
        throw new \BadMethodCallException('not used in this test');
    }

    public function completeFactual(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        throw new \BadMethodCallException('not used in this test');
    }

    public function completeCreative(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        throw new \BadMethodCallException('not used in this test');
    }
}

final class DocumentAnalyzerTest extends TestCase
{
    private function smallDocument(): SourceDocument
    {
        return new SourceDocument(
            title: 'Quarterly report',
            text: 'Revenue grew across all regions. Churn fell after the new onboarding flow.',
            sourceLabel: 'https://example.com/report',
            pageCount: 0,
            languageHint: 'en',
        );
    }

    /** @return array<string,mixed> */
    private function briefResult(string $language = 'en'): array
    {
        return [
            'title' => 'Quarterly report',
            'summary' => 'A concise overview of Q1 results.',
            'keyPoints' => ['Revenue up 12%', 'Churn down 3%'],
            'sections' => [
                ['heading' => 'Revenue', 'body' => 'Revenue grew across all regions.'],
                ['heading' => 'Churn', 'body' => 'Churn fell after onboarding.'],
            ],
            'audience' => 'Investors and analysts',
            'language' => $language,
        ];
    }

    public function testSmallDocumentUsesOneCallAndMapsJsonToContentBrief(): void
    {
        $fake = new FakeCompletionService([$this->briefResult('en')]);
        $analyzer = new DocumentAnalyzer($fake, new NullLogger(), chunkThreshold: 24000, chunkSize: 12000);

        $brief = $analyzer->analyze($this->smallDocument(), ['uid' => 1, 'be_user' => 7]);

        self::assertInstanceOf(ContentBrief::class, $brief);
        self::assertSame('Quarterly report', $brief->title);
        self::assertSame(['Revenue up 12%', 'Churn down 3%'], $brief->keyPoints);
        self::assertSame('Revenue', $brief->sections[0]['heading']);
        self::assertSame('en', $brief->language);

        self::assertCount(1, $fake->jsonCalls);
    }

    public function testPromptCarriesDocumentTextAndJsonBudgetOptions(): void
    {
        $fake = new FakeCompletionService([$this->briefResult('en')]);
        $analyzer = new DocumentAnalyzer($fake, new NullLogger(), chunkThreshold: 24000, chunkSize: 12000);

        $analyzer->analyze($this->smallDocument(), ['uid' => 1, 'be_user' => 7]);

        $call = $fake->jsonCalls[0];
        self::assertStringContainsString('Revenue grew across all regions.', $call['prompt']);
        $options = $call['options'];
        self::assertInstanceOf(ChatOptions::class, $options);
        self::assertSame('json', $options->getResponseFormat());
        self::assertNotSame('', (string) $options->getSystemPrompt());
        self::assertSame(7, $options->getBeUserUid());
    }

    public function testJsonLanguageOverridesEmptyHint(): void
    {
        $document = new SourceDocument(
            title: 'Bericht',
            text: 'Der Umsatz ist gestiegen.',
            sourceLabel: 'https://example.com/de',
            pageCount: 0,
            languageHint: '',
        );
        $fake = new FakeCompletionService([$this->briefResult('de')]);
        $analyzer = new DocumentAnalyzer($fake, new NullLogger());

        $brief = $analyzer->analyze($document, ['uid' => 1, 'be_user' => 0]);

        self::assertSame('de', $brief->language);
    }

    public function testLargeDocumentTakesMapReducePath(): void
    {
        $paragraph = str_repeat('Section content sentence. ', 400);
        $bigText = implode("\n\n", [$paragraph, $paragraph, $paragraph]);

        $document = new SourceDocument(
            title: 'Big report',
            text: $bigText,
            sourceLabel: 'https://example.com/big',
            pageCount: 0,
            languageHint: 'en',
        );

        $mapResult = ['summary' => 'Chunk summary.', 'keyPoints' => ['kp']];
        $results = [$mapResult, $mapResult, $mapResult, $this->briefResult('en')];
        $fake = new FakeCompletionService($results);
        $analyzer = new DocumentAnalyzer($fake, new NullLogger(), chunkThreshold: 20000, chunkSize: 11000);

        $brief = $analyzer->analyze($document, ['uid' => 1, 'be_user' => 7]);

        self::assertSame('Quarterly report', $brief->title);
        self::assertCount(4, $fake->jsonCalls);
        self::assertStringContainsString('Chunk summary.', $fake->jsonCalls[3]['prompt']);
    }

    public function testThrowsWhenRequiredKeysMissing(): void
    {
        $fake = new FakeCompletionService([['keyPoints' => ['x'], 'language' => 'en']]);
        $analyzer = new DocumentAnalyzer($fake, new NullLogger());

        $this->expectException(AnalysisException::class);
        $analyzer->analyze($this->smallDocument(), ['uid' => 1, 'be_user' => 0]);
    }
}
