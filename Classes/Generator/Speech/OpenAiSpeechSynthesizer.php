<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Speech;

use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;
use Netresearch\NrLlm\Specialized\Speech\TextToSpeechService;
use Netresearch\NrRepurpose\Rendering\RenderingException;

/**
 * Default SpeechSynthesizerInterface: delegates to nr-llm's TextToSpeechService (OpenAI TTS).
 * Turns are short (one dialogue line), so synthesizeToFile (<=4096 chars) is sufficient.
 */
final class OpenAiSpeechSynthesizer implements SpeechSynthesizerInterface
{
    public function __construct(private readonly TextToSpeechService $tts) {}

    public function isAvailable(): bool
    {
        return $this->tts->isAvailable();
    }

    public function synthesizeToFile(string $text, string $voice, string $outputPath): void
    {
        try {
            $this->tts->synthesizeToFile(
                mb_substr($text, 0, 4096),
                $outputPath,
                new SpeechSynthesisOptions(model: 'tts-1', voice: $voice, format: 'mp3'),
            );
        } catch (\Throwable $e) {
            throw RenderingException::because('TTS synthesis failed: ' . $e->getMessage(), 1749410000, $e);
        }
    }
}
