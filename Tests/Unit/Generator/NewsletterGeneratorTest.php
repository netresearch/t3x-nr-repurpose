<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrRepurpose\Generator\NewsletterGenerator;
use Netresearch\NrRepurpose\Service\CallerSource;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\MapTextLabels;
use PHPUnit\Framework\Attributes\DataProvider;

final class NewsletterGeneratorTest extends TextGeneratorTestCase
{
    protected function generator(): NewsletterGenerator
    {
        return new NewsletterGenerator($this->jobs, $this->budget(), $this->logger(), $this->completion, new MapTextLabels());
    }

    protected function validAnswer(): array
    {
        return [
            'subject'      => 'Revenue up 12 percent',
            'preheader'    => 'And a new branch in Leipzig',
            'paragraphs'   => ['Revenue grew by 12 percent.', 'A new branch opened in Leipzig.'],
            'callToAction' => 'Read the full report',
        ];
    }

    protected function expectedType(): string
    {
        return 'newsletter';
    }

    protected function wantColumn(): string
    {
        return 'want_newsletter';
    }

    protected function expectedOperation(): string
    {
        return CallerSource::GENERATE_NEWSLETTER;
    }

    protected function expectedLabel(): string
    {
        return 'Newsletter';
    }

    public function testStoresAllPartsStructuredAndAsPlainTextLabelledInTheTextsLanguage(): void
    {
        self::assertTrue($this->generatorWithAnswer()->generate($this->context(language: 'de')));

        self::assertSame($this->validAnswer(), $this->jobs->metadata()['content']);
        self::assertSame(
            "Betreff: Revenue up 12 percent\nPreheader: And a new branch in Leipzig\n\n"
            . "Revenue grew by 12 percent.\n\nA new branch opened in Leipzig.\n\nRead the full report",
            $this->jobs->row()['script_text'],
        );
    }

    public function testEnglishLabelsForAnEnglishText(): void
    {
        self::assertTrue($this->generatorWithAnswer()->generate($this->context(language: 'en')));

        self::assertStringStartsWith("Subject: Revenue up 12 percent\nPreheader: And a new", (string) $this->jobs->row()['script_text']);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function missingParts(): array
    {
        return [
            'subject'      => ['subject', ''],
            'preheader'    => ['preheader', null],
            'paragraphs'   => ['paragraphs', ['  ']],
            'callToAction' => ['callToAction', '   '],
        ];
    }

    #[DataProvider('missingParts')]
    public function testAMissingPartFailsTheArtifactNamingThePart(string $part, mixed $value): void
    {
        $answer        = $this->validAnswer();
        $answer[$part] = $value;

        self::assertFalse($this->generatorWithAnswer($answer)->generate($this->context()));

        $row = $this->jobs->row();
        self::assertSame('failed', $row['status']);
        self::assertSame('Newsletter generation error: the answer lacks ' . $part, $row['error_message']);
    }
}
