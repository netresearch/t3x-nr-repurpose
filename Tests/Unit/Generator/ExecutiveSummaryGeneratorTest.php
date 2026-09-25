<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrRepurpose\Generator\ExecutiveSummaryGenerator;
use Netresearch\NrRepurpose\Service\CallerSource;

final class ExecutiveSummaryGeneratorTest extends TextGeneratorTestCase
{
    protected function generator(): ExecutiveSummaryGenerator
    {
        return new ExecutiveSummaryGenerator($this->jobs, $this->budget(), $this->logger(), $this->completion);
    }

    protected function validAnswer(): array
    {
        return ['sentences' => ['Revenue grew 12 percent.', 'A branch opened in Leipzig.', 'Costs stayed flat.', 'Margins improved.', 'The outlook is positive.']];
    }

    protected function expectedType(): string
    {
        return 'exec_summary';
    }

    protected function wantColumn(): string
    {
        return 'want_exec_summary';
    }

    protected function expectedOperation(): string
    {
        return CallerSource::GENERATE_EXEC_SUMMARY;
    }

    protected function expectedLabel(): string
    {
        return 'Executive summary';
    }

    public function testStoresTheSentencesAndTheJoinedPlainText(): void
    {
        self::assertTrue($this->generatorWithAnswer()->generate($this->context()));

        self::assertSame(
            'Revenue grew 12 percent. A branch opened in Leipzig. Costs stayed flat. Margins improved. The outlook is positive.',
            $this->jobs->row()['script_text'],
        );
        self::assertSame($this->validAnswer()['sentences'], $this->jobs->metadata()['content']['sentences']);
    }

    public function testMoreThanEightSentencesAreCutToTheFirstEight(): void
    {
        $sentences = array_map(static fn (int $i): string => sprintf('Fact %d.', $i), range(1, 11));

        self::assertTrue($this->generatorWithAnswer(['sentences' => $sentences])->generate($this->context()));

        self::assertSame(array_slice($sentences, 0, 8), $this->jobs->metadata()['content']['sentences']);
    }

    public function testBlankSentencesAreDroppedAndTrimmed(): void
    {
        self::assertTrue($this->generatorWithAnswer(['sentences' => ['  First.  ', '   ', 'Second.']])->generate($this->context()));

        self::assertSame(['First.', 'Second.'], $this->jobs->metadata()['content']['sentences']);
    }

    public function testAnAnswerWithoutUsableSentencesFailsTheArtifactReadably(): void
    {
        self::assertFalse($this->generatorWithAnswer(['sentences' => ['   ', '']])->generate($this->context()));

        $row = $this->jobs->row();
        self::assertSame('failed', $row['status']);
        self::assertSame('Executive summary generation error: the answer contains no summary sentences', $row['error_message']);
    }
}
