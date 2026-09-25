<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Testing\FakeBudgetService;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\NrRepurpose\Domain\ValueObject\CapabilityGrants;
use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\ResolvedPromptSnippets;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\AbstractTextGenerator;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use Netresearch\NrRepurpose\Pipeline\JobProgress;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\ArtifactRecordingJobRepository;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StatusRecordingJobRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The shared text-generator flow, run once per concrete generator: each subclass names
 * its generator, a valid answer and what it expects; the inherited cases pin the parts
 * AbstractTextGenerator owns (schema pre-flight, call options, prompt, failure rows).
 */
abstract class TextGeneratorTestCase extends TestCase
{
    protected ArtifactRecordingJobRepository $jobs;

    protected FakeCompletionService $completion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobs       = new ArtifactRecordingJobRepository();
        $this->completion = new FakeCompletionService();
    }

    abstract protected function generator(): AbstractTextGenerator;

    /** @return array<string, mixed> a schema-valid answer for this format */
    abstract protected function validAnswer(): array;

    abstract protected function expectedType(): string;

    abstract protected function wantColumn(): string;

    abstract protected function expectedOperation(): string;

    abstract protected function expectedLabel(): string;

    protected function context(bool $want = true, ResolvedPromptSnippets $snippets = new ResolvedPromptSnippets(), string $language = 'de'): GenerationContext
    {
        $document = new SourceDocument('Quartalsbericht', 'text', 'https://example.com/report', 0, $language);
        $brief    = new ContentBrief(
            'Quartalsbericht',
            'Der Umsatz stieg um 12 Prozent.',
            ['Umsatz +12 %', 'Neue Filiale in Leipzig'],
            [['heading' => 'Umsatz', 'body' => 'Der Umsatz stieg im dritten Quartal um 12 Prozent.']],
            'Management',
            $language,
        );

        return new GenerationContext(
            ['uid' => 31, 'theme' => 'nr', 'be_user' => 7, $this->wantColumn() => $want ? 1 : 0],
            $document,
            $brief,
            'nr',
            7,
            $snippets,
            // Text formats need no grant: prove it by granting nothing.
            grants: new CapabilityGrants(audio: false, vision: false),
        );
    }

    protected function generatorWithAnswer(mixed $answer = null): AbstractTextGenerator
    {
        $this->completion->structuredResult = is_array($answer) ? $answer : $this->validAnswer();

        return $this->generator();
    }

    protected function budget(): FakeBudgetService
    {
        return new FakeBudgetService();
    }

    protected function logger(): NullLogger
    {
        return new NullLogger();
    }

    /**
     * nr-llm refuses a schema outside its strict subset before the first provider call
     * (code 1784500003). The fake completion service never checks, so without this case
     * the whole suite could be green while every production call throws.
     */
    public function testResponseSchemaLiesInsideNrLlmsStrictSubset(): void
    {
        self::assertTrue((new JsonSchemaValidator())->supportsSchema($this->generator()->responseSchema()));
    }

    public function testTheValidAnswerMatchesTheSchemaStrictly(): void
    {
        self::assertTrue((new JsonSchemaValidator())->validateStrict($this->validAnswer(), $this->generator()->responseSchema()));
    }

    public function testSupportsReadsTheWantFlag(): void
    {
        $generator = $this->generator();
        self::assertTrue($generator->supports($this->context(true)));
        self::assertFalse($generator->supports($this->context(false)));
    }

    public function testOneStructuredCallWithSchemaCallerSourceAndBudgetUser(): void
    {
        $generator = $this->generatorWithAnswer();

        self::assertTrue($generator->generate($this->context()));

        self::assertCount(1, $this->completion->completeStructuredCalls);
        self::assertSame([], $this->completion->completeJsonCalls);
        $call = $this->completion->completeStructuredCalls[0];
        self::assertSame($generator->responseSchema(), $call['schema']);
        $options = $call['options'];
        self::assertNotNull($options);
        self::assertSame('nr_repurpose', $options->getCallerSourceExtension());
        self::assertSame($this->expectedOperation(), $options->getCallerSourceOperation());
        self::assertSame(7, $options->getBeUserUid());
        self::assertSame('json', $options->getResponseFormat());
    }

    public function testPromptCarriesTheSourceAndTheDetectedLanguage(): void
    {
        $this->generatorWithAnswer()->generate($this->context(language: 'de'));

        $prompt = $this->completion->completeStructuredCalls[0]['prompt'];
        self::assertStringContainsString('Write in language code "de".', $prompt);
        self::assertStringContainsString('Der Umsatz stieg im dritten Quartal um 12 Prozent.', $prompt);
        self::assertStringContainsString('https://example.com/report', $prompt);
        self::assertStringContainsString('Use only facts stated in the content above', $prompt);
    }

    public function testAudienceAndToneSnippetsReachThePrompt(): void
    {
        $snippets = new ResolvedPromptSnippets(textSections: "TARGET AUDIENCE:\nInvestors\n\nTONE OF VOICE:\nSober");
        $this->generatorWithAnswer()->generate($this->context(snippets: $snippets));

        self::assertStringEndsWith("TARGET AUDIENCE:\nInvestors\n\nTONE OF VOICE:\nSober", $this->completion->completeStructuredCalls[0]['prompt']);
    }

    public function testDoneRowsCarryPlainTextContentAndTheVerbatimPrompts(): void
    {
        self::assertTrue($this->generatorWithAnswer()->generate($this->context()));

        self::assertNotSame([], $this->jobs->inserted);
        foreach ($this->jobs->inserted as $uid => $insert) {
            self::assertSame($this->expectedType(), $insert['type']);
            $update = $this->jobs->updates[$uid];
            self::assertSame('done', $update['status']);
            self::assertNotSame('', $update['script_text']);
            $metadata = json_decode((string) $update['metadata'], true);
            self::assertIsArray($metadata);
            self::assertIsArray($metadata['content']);
            self::assertSame($this->completion->completeStructuredCalls[0]['prompt'], $metadata['prompts']['user']);
            self::assertArrayHasKey('system', $metadata['prompts']);
        }
    }

    public function testProviderOrSchemaFailureRecordsOneFailedRowWithAReadableReason(): void
    {
        $this->completion->throwable = new RuntimeException('Structured completion did not match the required schema after one repair attempt.');

        self::assertFalse($this->generatorWithAnswer()->generate($this->context()));

        self::assertCount(1, $this->jobs->inserted);
        $row = $this->jobs->row('default');
        self::assertSame($this->expectedType(), $row['type']);
        self::assertSame('failed', $row['status']);
        self::assertSame(
            $this->expectedLabel() . ' generation error: Structured completion did not match the required schema after one repair attempt.',
            $row['error_message'],
        );
    }

    public function testReportsAWritingStep(): void
    {
        $progressJobs = new StatusRecordingJobRepository();
        $ctx          = $this->context()->withProgress(new JobProgress($progressJobs, 31, 30.0, 100.0));

        self::assertTrue($this->generatorWithAnswer()->generate($ctx));
        self::assertSame([$this->expectedLabel() . ': writing text'], $progressJobs->steps());
    }
}
