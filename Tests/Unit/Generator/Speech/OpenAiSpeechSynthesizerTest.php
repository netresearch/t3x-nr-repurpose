<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Speech;

use Netresearch\NrLlm\Specialized\Speech\TextToSpeechService;
use Netresearch\NrRepurpose\Generator\Speech\OpenAiSpeechSynthesizer;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use ReflectionClass;

final class OpenAiSpeechSynthesizerTest extends TestCase
{
    public function testModelConstantPinsTheTts1Fallback(): void
    {
        // getModel() delegates to nr-llm's TextToSpeechService::resolveModelForConfiguration(),
        // falling back to this constant; asserting the constant pins the fallback without
        // reflection (nr-llm's TextToSpeechService is final, not mockable). The
        // SpeechSynthesizerInterface seam exists precisely so every other test stubs the
        // resolved model instead.
        self::assertSame('tts-1', OpenAiSpeechSynthesizer::MODEL);
    }

    /**
     * The failure reason ends up in the podcast artifact's error_message, which every module
     * user sees; the provider's message belongs in the server log only.
     */
    public function testAFailedSynthesisThrowsAFixedMessageAndLogsTheCause(): void
    {
        // nr-llm's service is final; an instance without its constructor fails on first
        // use with an Error, which stands in for any provider failure here.
        $service = (new ReflectionClass(TextToSpeechService::class))->newInstanceWithoutConstructor();
        $logger  = new RecordingLogger();

        try {
            (new OpenAiSpeechSynthesizer($service, $logger))->synthesizeToFile('Hello', 'alloy', '/var/secret-dir/turn.mp3');
            self::fail('Expected a RenderingException');
        } catch (RenderingException $e) {
            self::assertSame('TTS synthesis failed', $e->getMessage());
            $inner = $e->getPrevious();
            self::assertNotNull($inner);
            self::assertStringNotContainsString($inner->getMessage(), $e->getMessage());
        }

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame($inner, $logger->records[0]['context']['exception'] ?? null);
    }
}
