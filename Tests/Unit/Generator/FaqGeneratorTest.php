<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrRepurpose\Generator\FaqGenerator;
use Netresearch\NrRepurpose\Service\CallerSource;

final class FaqGeneratorTest extends TextGeneratorTestCase
{
    protected function generator(): FaqGenerator
    {
        return new FaqGenerator($this->jobs, $this->budget(), $this->logger(), $this->completion);
    }

    protected function validAnswer(): array
    {
        return ['faq' => [
            ['question' => 'How much did revenue grow?', 'answer' => 'By 12 percent.'],
            ['question' => 'Where did a branch open?', 'answer' => 'In Leipzig.'],
        ]];
    }

    protected function expectedType(): string
    {
        return 'faq';
    }

    protected function wantColumn(): string
    {
        return 'want_faq';
    }

    protected function expectedOperation(): string
    {
        return CallerSource::GENERATE_FAQ;
    }

    protected function expectedLabel(): string
    {
        return 'FAQ';
    }

    public function testStoresThePairsStructuredAndAsPlainText(): void
    {
        self::assertTrue($this->generatorWithAnswer()->generate($this->context()));

        self::assertSame($this->validAnswer()['faq'], $this->jobs->metadata()['content']['faq']);
        self::assertSame(
            "Q: How much did revenue grow?\nA: By 12 percent.\n\nQ: Where did a branch open?\nA: In Leipzig.",
            $this->jobs->row()['script_text'],
        );
    }

    public function testStoresSchemaOrgFaqPageJsonLd(): void
    {
        self::assertTrue($this->generatorWithAnswer()->generate($this->context()));

        $jsonLd = json_decode((string) $this->jobs->metadata()['content']['jsonLd'], true);
        self::assertSame('https://schema.org', $jsonLd['@context']);
        self::assertSame('FAQPage', $jsonLd['@type']);
        self::assertSame(
            [
                ['@type' => 'Question', 'name' => 'How much did revenue grow?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'By 12 percent.']],
                ['@type' => 'Question', 'name' => 'Where did a branch open?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'In Leipzig.']],
            ],
            $jsonLd['mainEntity'],
        );
    }

    public function testJsonLdCannotCloseTheScriptElementItIsPastedInto(): void
    {
        $jsonLd = (new FaqGenerator($this->jobs, $this->budget(), $this->logger(), $this->completion))
            ->jsonLd([['question' => 'Is </script><b>x</b> safe?', 'answer' => 'Yes.']]);

        self::assertStringNotContainsString('</script>', $jsonLd);
        self::assertStringNotContainsString('<', $jsonLd);
        self::assertSame('Is </script><b>x</b> safe?', json_decode($jsonLd, true)['mainEntity'][0]['name']);
    }

    public function testIncompletePairsAreDroppedAndTheRestIsCappedAtTen(): void
    {
        $pairs = [['question' => 'Only a question?', 'answer' => '  '], 'not an object'];
        foreach (range(1, 12) as $i) {
            $pairs[] = ['question' => sprintf('Q%d?', $i), 'answer' => sprintf('A%d.', $i)];
        }

        self::assertTrue($this->generatorWithAnswer(['faq' => $pairs])->generate($this->context()));

        $faq = $this->jobs->metadata()['content']['faq'];
        self::assertCount(10, $faq);
        self::assertSame(['question' => 'Q1?', 'answer' => 'A1.'], $faq[0]);
        self::assertSame(['question' => 'Q10?', 'answer' => 'A10.'], $faq[9]);
    }

    public function testAnAnswerWithoutACompletePairFailsTheArtifactReadably(): void
    {
        self::assertFalse($this->generatorWithAnswer(['faq' => [['question' => 'Unanswered?', 'answer' => '']]])->generate($this->context()));

        $row = $this->jobs->row();
        self::assertSame('failed', $row['status']);
        self::assertSame('FAQ generation error: the answer contains no complete question/answer pair', $row['error_message']);
    }
}
