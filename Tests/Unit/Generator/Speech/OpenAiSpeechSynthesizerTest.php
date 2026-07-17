<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Speech;

use Netresearch\NrRepurpose\Generator\Speech\OpenAiSpeechSynthesizer;
use PHPUnit\Framework\TestCase;

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
}
