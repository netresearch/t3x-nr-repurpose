<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Speech;

use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;
use Netresearch\NrLlm\Specialized\Speech\TextToSpeechService;
use Netresearch\NrRepurpose\Rendering\RenderingException;

/**
 * Default SpeechSynthesizerInterface: delegates to nr-llm's TextToSpeechService (OpenAI TTS).
 * The effective model comes from nr-llm's Model registry (the active text-to-speech model,
 * preferring the default record) and falls back to tts-1 when the registry has none, so
 * editors switch models in the nr-llm backend module without code changes.
 * Turns are short (one dialogue line), so synthesizeToFile (<=4096 chars) is sufficient.
 */
final class OpenAiSpeechSynthesizer implements SpeechSynthesizerInterface
{
    /** Documented fallback when nr-llm's Model registry has no active text-to-speech model. */
    public const MODEL = 'tts-1';

    /** Effective model, resolved lazily once per instance by getModel(). */
    private ?string $model = null;

    public function __construct(private readonly TextToSpeechService $tts) {}

    public function isAvailable(): bool
    {
        return $this->tts->isAvailable();
    }

    /**
     * The effective model: the active text-to-speech model from nr-llm's Model registry,
     * falling back to self::MODEL. synthesizeToFile() uses the same resolved value, so the
     * podcast metadata recorded from this method always names the model that actually ran.
     */
    public function getModel(): string
    {
        // The method_exists() guard keeps the extension installable against an nr-llm
        // dev-main without resolveDefaultModel(); drop it once nr-llm's specialized
        // model registry change is merged and required here.
        $this->model ??= method_exists($this->tts, 'resolveDefaultModel')
            ? $this->tts->resolveDefaultModel(self::MODEL)
            : self::MODEL;

        return $this->model;
    }

    public function synthesizeToFile(string $text, string $voice, string $outputPath): void
    {
        try {
            $this->tts->synthesizeToFile(
                mb_substr($text, 0, 4096),
                $outputPath,
                new SpeechSynthesisOptions(model: $this->getModel(), voice: $voice, format: 'mp3'),
            );
        } catch (\Throwable $e) {
            throw RenderingException::because('TTS synthesis failed: ' . $e->getMessage(), 1749410000, $e);
        }
    }
}
