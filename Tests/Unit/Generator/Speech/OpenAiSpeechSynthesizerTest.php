<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Speech;

use Netresearch\NrRepurpose\Generator\Speech\OpenAiSpeechSynthesizer;
use PHPUnit\Framework\TestCase;

final class OpenAiSpeechSynthesizerTest extends TestCase
{
    public function testModelConstantPinsTheTts1Fallback(): void
    {
        // getModel() resolves the effective model from nr-llm's Model registry and falls
        // back to this constant; asserting the constant pins the fallback without
        // reflection (nr-llm's TextToSpeechService is final, not mockable).
        //
        // The method_exists() fallback branch inside getModel() is intentionally not
        // unit-tested: exercising it needs a TextToSpeechService instance, the class is
        // final (unmockable), and a reflection-created uninitialized instance would make
        // the test outcome depend on whether the installed nr-llm already ships
        // resolveDefaultModel(). The SpeechSynthesizerInterface seam exists precisely so
        // every other test stubs the resolved model instead.
        self::assertSame('tts-1', OpenAiSpeechSynthesizer::MODEL);
    }
}
