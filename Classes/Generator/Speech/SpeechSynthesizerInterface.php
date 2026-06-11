<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Speech;

/**
 * Thin, provider-agnostic seam over nr-llm's (final) TextToSpeechService so generators and
 * their unit tests do not depend on the concrete class. The default implementation wraps
 * OpenAI TTS; a future provider can be swapped without touching the podcast generator.
 */
interface SpeechSynthesizerInterface
{
    public function isAvailable(): bool;

    /**
     * The TTS model id this synthesizer calls (e.g. "tts-1"). The podcast generator records
     * it in the artifact metadata so the result view shows the model that actually ran.
     */
    public function getModel(): string;

    /**
     * Synthesize $text in the given voice and write the audio to $outputPath (mp3).
     *
     * @throws \Netresearch\NrRepurpose\Rendering\RenderingException on synthesis failure
     */
    public function synthesizeToFile(string $text, string $voice, string $outputPath): void;
}
