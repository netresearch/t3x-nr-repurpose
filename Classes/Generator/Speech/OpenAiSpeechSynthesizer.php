<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Speech;

use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;
use Netresearch\NrLlm\Specialized\Speech\TextToSpeechService;
use Netresearch\NrRepurpose\Rendering\RenderingException;

/**
 * Default SpeechSynthesizerInterface: delegates to nr-llm's TextToSpeechService (OpenAI TTS).
 * The effective model resolves through nr-llm's three-tier system: the "nr_repurpose_tts"
 * Configuration record decides (editors swap the model there without touching any
 * consumer), falling back to the registry's active text-to-speech model and finally to
 * tts-1. Turns are short (one dialogue line), so synthesizeToFile (<=4096 chars) is
 * sufficient.
 */
final class OpenAiSpeechSynthesizer implements SpeechSynthesizerInterface
{
    /** The nr-llm Configuration record (identifier) steering speech synthesis. */
    public const CONFIGURATION = 'nr_repurpose_tts';

    /** Documented fallback when neither the Configuration nor the Model registry resolves. */
    public const MODEL = 'tts-1';

    /** Effective model, resolved lazily once per instance by getModel(). */
    private ?string $model = null;

    public function __construct(private readonly TextToSpeechService $tts) {}

    public function isAvailable(): bool
    {
        return $this->tts->isAvailable();
    }

    /**
     * The effective model: the "nr_repurpose_tts" Configuration's model, else the active
     * text-to-speech model from nr-llm's Model registry, else self::MODEL.
     * synthesizeToFile() uses the same resolved value, so the podcast metadata recorded
     * from this method always names the model that actually ran.
     */
    public function getModel(): string
    {
        // The method_exists() guards keep the extension installable against an nr-llm
        // dev-main without the configuration-resolution layer; drop them once nr-llm's
        // specialized configuration change is merged and required here.
        $this->model ??= match (true) {
            method_exists($this->tts, 'resolveModelForConfiguration')
                => $this->tts->resolveModelForConfiguration(self::CONFIGURATION, self::MODEL),
            method_exists($this->tts, 'resolveDefaultModel')
                => $this->tts->resolveDefaultModel(self::MODEL),
            default => self::MODEL,
        };

        return $this->model;
    }

    public function synthesizeToFile(string $text, string $voice, string $outputPath): void
    {
        try {
            $this->tts->synthesizeToFile(
                mb_substr($text, 0, 4096),
                $outputPath,
                $this->buildOptions($voice),
            );
        } catch (\Throwable $e) {
            throw RenderingException::because('TTS synthesis failed: ' . $e->getMessage(), 1749410000, $e);
        }
    }

    /**
     * The 'configuration' option is pure usage-attribution metadata in nr-llm (per-
     * configuration cost breakdowns); pass it only when the installed options class
     * already carries the property — a named argument unknown to the constructor
     * would be a fatal, not a graceful degradation.
     */
    private function buildOptions(string $voice): SpeechSynthesisOptions
    {
        $arguments = ['model' => $this->getModel(), 'voice' => $voice, 'format' => 'mp3'];
        if (property_exists(SpeechSynthesisOptions::class, 'configuration')) {
            $arguments['configuration'] = self::CONFIGURATION;
        }

        return new SpeechSynthesisOptions(...$arguments);
    }
}
