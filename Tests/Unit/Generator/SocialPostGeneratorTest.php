<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator;

use Netresearch\NrRepurpose\Generator\SocialPostGenerator;
use Netresearch\NrRepurpose\Generator\Support\TextLimiter;
use Netresearch\NrRepurpose\Service\CallerSource;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\ArtifactRecordingJobRepository;
use RuntimeException;

final class SocialPostGeneratorTest extends TextGeneratorTestCase
{
    protected function generator(): SocialPostGenerator
    {
        return new SocialPostGenerator($this->jobs, $this->budget(), $this->logger(), $this->completion, new TextLimiter());
    }

    protected function validAnswer(): array
    {
        return [
            'linkedin'  => 'Revenue grew by 12 percent. Here is what drove it.',
            'x'         => 'Revenue +12 %. New branch in Leipzig.',
            'instagram' => ['caption' => 'Big quarter for us!', 'hashtags' => ['growth', 'Leipzig']],
        ];
    }

    protected function expectedType(): string
    {
        return 'social_post';
    }

    protected function wantColumn(): string
    {
        return 'want_social_post';
    }

    protected function expectedOperation(): string
    {
        return CallerSource::GENERATE_SOCIAL_POST;
    }

    protected function expectedLabel(): string
    {
        return 'Social posts';
    }

    public function testOneRowPerPlatformVariant(): void
    {
        self::assertTrue($this->generatorWithAnswer()->generate($this->context()));

        self::assertSame(['linkedin', 'x', 'instagram'], array_column(array_values($this->jobs->inserted), 'variant'));
        self::assertSame('Revenue grew by 12 percent. Here is what drove it.', $this->jobs->row('linkedin')['script_text']);
        self::assertSame('Revenue +12 %. New branch in Leipzig.', $this->jobs->row('x')['script_text']);
        self::assertSame("Big quarter for us!\n\n#growth #Leipzig", $this->jobs->row('instagram')['script_text']);

        $x = $this->jobs->metadata('x')['content'];
        self::assertSame(280, $x['maxChars']);
        self::assertSame(37, $x['length']);
        self::assertFalse($x['truncated']);
    }

    public function testEachPostIsCutAtASentenceBoundaryWithinItsPlatformLimit(): void
    {
        $sentence = 'Revenue grew by twelve percent this quarter. ';   // 45 characters with the space
        $answer   = [
            'linkedin'  => str_repeat($sentence, 80),   // 3600
            'x'         => str_repeat($sentence, 8),    // 360
            'instagram' => ['caption' => str_repeat($sentence, 60), 'hashtags' => ['growth']],   // 2700
        ];

        self::assertTrue($this->generatorWithAnswer($answer)->generate($this->context()));

        foreach (['linkedin' => 3000, 'x' => 280, 'instagram' => 2200] as $platform => $limit) {
            $content = $this->jobs->metadata($platform)['content'];
            $text    = $this->jobs->row($platform)['script_text'];
            self::assertIsString($text);
            self::assertLessThanOrEqual($limit, mb_strlen($text), $platform);
            self::assertSame($limit, $content['maxChars']);
            self::assertSame(mb_strlen($text), $content['length']);
            self::assertTrue($content['truncated'], $platform);
        }

        // Whole sentences only: six end at character 269, the seventh would end at 314.
        self::assertSame(rtrim(str_repeat($sentence, 6)), $this->jobs->row('x')['script_text']);
        self::assertStringEndsWith("percent this quarter.\n\n#growth", (string) $this->jobs->row('instagram')['script_text']);
    }

    public function testAFailedWriteFailsOnlyThatVariant(): void
    {
        // The done-write of the second row (the "x" post) fails; the failure write succeeds.
        $this->jobs = new class extends ArtifactRecordingJobRepository {
            public function updateArtifact(int $artifactUid, array $fields): void
            {
                if (($fields['status'] ?? null) === 'done' && $this->inserted[$artifactUid]['variant'] === 'x') {
                    throw new SimulatedStorageFailure('connection lost');
                }

                parent::updateArtifact($artifactUid, $fields);
            }
        };

        self::assertTrue($this->generatorWithAnswer()->generate($this->context()));

        self::assertSame('done', $this->jobs->row('linkedin')['status']);
        self::assertSame('done', $this->jobs->row('instagram')['status']);
        self::assertSame('failed', $this->jobs->row('x')['status']);
        self::assertSame('Social posts (x) storage error: connection lost', $this->jobs->row('x')['error_message']);
    }

    public function testTheHashtagLineCountsAgainstTheInstagramLimit(): void
    {
        // The first sentence ends at character 2195: it fits 2200 on its own, but not
        // next to "\n\n#growth #Leipzig" (18 characters), so the caption must give way.
        $answer                         = $this->validAnswer();
        $answer['instagram']['caption'] = rtrim(str_repeat('word ', 439)) . '. And more.';

        self::assertTrue($this->generatorWithAnswer($answer)->generate($this->context()));

        $text = (string) $this->jobs->row('instagram')['script_text'];
        self::assertLessThanOrEqual(2200, mb_strlen($text));
        self::assertStringEndsWith("…\n\n#growth #Leipzig", $text);
    }

    public function testHashtagsCannotCrowdTheCaptionBelowHalfTheLimit(): void
    {
        $answer                          = $this->validAnswer();
        $answer['instagram']['caption']  = str_repeat('Revenue grew by twelve percent this quarter. ', 60);
        $answer['instagram']['hashtags'] = array_map(static fn (int $i): string => sprintf('verylonghashtag%02d%s', $i, str_repeat('x', 32)), range(1, 30));

        self::assertTrue($this->generatorWithAnswer($answer)->generate($this->context()));

        $content = $this->jobs->metadata('instagram')['content'];
        self::assertLessThan(30, count($content['hashtags']));
        self::assertGreaterThanOrEqual(1050, mb_strlen($content['caption']));
        self::assertLessThanOrEqual(2200, $content['length']);
    }

    public function testHashtagsAreNormalisedDeduplicatedAndCappedAtThirty(): void
    {
        $tags = ['#growth', 'Growth', '  new branch ', '', '##Leipzig'];
        foreach (range(1, 40) as $i) {
            $tags[] = 'tag' . $i;
        }

        $answer                          = $this->validAnswer();
        $answer['instagram']['hashtags'] = $tags;
        self::assertTrue($this->generatorWithAnswer($answer)->generate($this->context()));

        $hashtags = $this->jobs->metadata('instagram')['content']['hashtags'];
        self::assertCount(30, $hashtags);
        self::assertSame(['#growth', '#newbranch', '#Leipzig', '#tag1'], array_slice($hashtags, 0, 4));
    }

    public function testAMissingPlatformFailsTheArtifactNamingThePlatform(): void
    {
        $answer      = $this->validAnswer();
        $answer['x'] = '   ';

        self::assertFalse($this->generatorWithAnswer($answer)->generate($this->context()));

        self::assertCount(1, $this->jobs->inserted);
        $row = $this->jobs->row('default');
        self::assertSame('failed', $row['status']);
        self::assertSame('Social posts generation error: the answer has no "x" post', $row['error_message']);
    }
}

/** Dedicated exception for the simulated write failure (php:S112 — no generic RuntimeException). */
final class SimulatedStorageFailure extends RuntimeException {}
