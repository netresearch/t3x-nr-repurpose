<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service\Preset;

use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetProviderInterface;
use Netresearch\NrRepurpose\Generator\Image\DallEImageGenerator;
use Netresearch\NrRepurpose\Generator\Speech\OpenAiSpeechSynthesizer;
use Netresearch\NrRepurpose\Service\ConfiguredCompletionService;
use Netresearch\NrRepurpose\Service\UseCase\RepurposeStarterPackProvider;

/**
 * Declares the nr-llm Configuration records this extension needs (ADR-056), so an
 * administrator imports them with one click from the nr_llm Configurations backend
 * module instead of hand-creating each record.
 *
 * The text configuration supports selection by criteria. For image and speech
 * the specialized services look for a directly assigned model on the matching
 * configuration before consulting the model registry.
 *
 * Discovered automatically via the `nr_llm.configuration_preset` DI tag (autoconfigured).
 */
final class RepurposeConfigurationPresetProvider implements ConfigurationPresetProviderInterface
{
    public const string TEXT_CONFIGURATION = 'nr_repurpose_text';

    /**
     * The completion (text) preset, as one object.
     *
     * Named rather than inlined because {@see RepurposeStarterPackProvider}
     * declares the same configuration: a pack carries a preset, and two
     * hand-written copies of one identifier drift into two records the operator
     * has to tell apart.
     */
    public static function textPreset(): ConfigurationPreset
    {
        return new ConfigurationPreset(
            identifier: self::TEXT_CONFIGURATION,
            name: 'Content Repurpose: Text',
            description: 'Chat model for content briefs, podcast scripts, diagram data and '
                . 'story slides. Import this configuration to select the model used for text generation.',
            // CHAT only, deliberately. The pipeline does request JSON — every
            // completion passes `responseFormat: 'json'` — but requiring
            // ModelCapability::JSON_MODE here makes this preset unimportable
            // on every installation: no model discoverer in nr-llm assigns
            // that capability to anything, so ModelSelectionService finds no
            // candidate and the import is refused with "no active model
            // satisfies its configuration requirement". Nothing on the call
            // path checks it either — `responseFormat` is a plain provider
            // option. A criterion nothing can satisfy and nothing enforces
            // only blocks the import (netresearch/typo3-demo#236).
            criteria: new ModelSelectionCriteria(
                capabilities: [ModelCapability::CHAT->value],
            ),
            maxTokens: ConfiguredCompletionService::MIN_OUTPUT_TOKENS,
        );
    }

    /**
     * @return list<ConfigurationPreset>
     */
    public function getPresets(): array
    {
        return [
            // textPreset() is deliberately NOT returned here. The starter pack
            // carries it ({@see \Netresearch\NrRepurpose\Service\UseCase\RepurposeStarterPackProvider}),
            // and nr-llm publishes every pack's configuration preset into the
            // same registry through UseCasePackPresetProvider (ADR-163, nr-llm
            // 0.29+). Declaring it in both places put the identifier into that
            // registry twice, and the registry refuses a duplicate with
            // LogicException #1789347004 — which 500s the whole nr_llm
            // Configurations backend module, not just this preset.
            //
            // The image and speech presets stay: no pack carries them, and the
            // specialized generators look them up by identifier.
            new ConfigurationPreset(
                identifier: DallEImageGenerator::CONFIGURATION,
                name: 'Content Repurpose: Images',
                description: 'Assign a supported image model directly to use it for diagrams and '
                    . 'Instagram stories. Set a system prompt to steer the visual style.',
                criteria: new ModelSelectionCriteria(
                    capabilities: [ModelCapability::IMAGE->value],
                ),
            ),
            new ConfigurationPreset(
                identifier: OpenAiSpeechSynthesizer::CONFIGURATION,
                name: 'Content Repurpose: Speech',
                description: 'Assign a supported speech model directly to use it for podcast narration.',
                criteria: new ModelSelectionCriteria(
                    capabilities: [ModelCapability::TEXT_TO_SPEECH->value],
                ),
            ),
        ];
    }
}
