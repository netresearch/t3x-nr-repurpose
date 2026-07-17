<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service\Preset;

use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetProviderInterface;
use Netresearch\NrRepurpose\Generator\Image\DallEImageGenerator;
use Netresearch\NrRepurpose\Generator\Speech\OpenAiSpeechSynthesizer;

/**
 * Declares the nr-llm Configuration records this extension needs (ADR-056), so an
 * administrator imports them with one click from the nr_llm Configurations backend
 * module instead of hand-creating each record.
 *
 * Presets carry model REQUIREMENTS (capabilities) only — never a concrete provider,
 * model, or API key. nr_llm resolves an imported criteria-mode record at runtime
 * against whatever providers and models the instance has configured.
 *
 * The image and speech presets are looked up by identifier by the specialized
 * generators ({@see DallEImageGenerator::CONFIGURATION}, {@see OpenAiSpeechSynthesizer::CONFIGURATION}).
 * The text preset ({@see self::TEXT_CONFIGURATION}) is a ready-made default for the
 * completion pipeline (briefs, podcast scripts, diagram data, story slides), which
 * resolves the instance-default configuration — import it and mark it default.
 *
 * Discovered automatically via the `nr_llm.configuration_preset` DI tag (autoconfigured).
 */
final class RepurposeConfigurationPresetProvider implements ConfigurationPresetProviderInterface
{
    /**
     * Identifier of the completion (text) configuration. Unlike the image and speech
     * records this is not referenced by name in code — the text pipeline uses the
     * instance-default configuration — so this preset is imported and marked default.
     */
    public const TEXT_CONFIGURATION = 'nr_repurpose_text';

    /**
     * @return list<ConfigurationPreset>
     */
    public function getPresets(): array
    {
        return [
            new ConfigurationPreset(
                identifier: self::TEXT_CONFIGURATION,
                name: 'Content Repurpose: Text',
                description: 'Chat model with JSON output for content briefs, podcast scripts, '
                    . 'diagram data and story slides. Import it and mark it as the default '
                    . 'configuration — the text pipeline uses the instance default.',
                criteria: new ModelSelectionCriteria(
                    capabilities: [ModelCapability::CHAT->value, ModelCapability::JSON_MODE->value],
                ),
            ),
            new ConfigurationPreset(
                identifier: DallEImageGenerator::CONFIGURATION,
                name: 'Content Repurpose: Images',
                description: 'Text-to-image model for the diagram and Instagram-story visuals. '
                    . 'Set a system prompt on the imported record to steer the visual style.',
                criteria: new ModelSelectionCriteria(
                    capabilities: [ModelCapability::IMAGE->value],
                ),
            ),
            new ConfigurationPreset(
                identifier: OpenAiSpeechSynthesizer::CONFIGURATION,
                name: 'Content Repurpose: Speech',
                description: 'Text-to-speech model for podcast narration.',
                criteria: new ModelSelectionCriteria(
                    capabilities: [ModelCapability::TEXT_TO_SPEECH->value],
                ),
            ),
        ];
    }
}
