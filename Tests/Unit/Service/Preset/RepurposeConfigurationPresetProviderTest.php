<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Service\Preset;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\UseCase\UseCasePack;
use Netresearch\NrRepurpose\Generator\Image\DallEImageGenerator;
use Netresearch\NrRepurpose\Generator\Speech\OpenAiSpeechSynthesizer;
use Netresearch\NrRepurpose\Service\Preset\RepurposeConfigurationPresetProvider;
use Netresearch\NrRepurpose\Service\UseCase\RepurposeStarterPackProvider;
use PHPUnit\Framework\TestCase;

final class RepurposeConfigurationPresetProviderTest extends TestCase
{
    /**
     * @return array<string, ConfigurationPreset>
     */
    private function presetsByIdentifier(): array
    {
        $presets = (new RepurposeConfigurationPresetProvider())->getPresets();

        $indexed = [];
        foreach ($presets as $preset) {
            $indexed[$preset->identifier] = $preset;
        }

        return $indexed;
    }

    /**
     * This provider declares the image and speech presets and NOT the text one.
     *
     * The text preset reaches nr-llm's registry through the starter pack, which
     * nr-llm republishes via UseCasePackPresetProvider (ADR-163). Declaring it
     * here as well put one identifier into that registry twice, and the registry
     * refuses a duplicate with LogicException #1789347004 — which 500s the whole
     * nr_llm Configurations module. This case asserted the opposite until then,
     * so it pinned the defect rather than the rule.
     */
    public function testDeclaresTheImageAndSpeechPresetsAndLeavesTheTextOneToThePack(): void
    {
        $identifiers = array_keys($this->presetsByIdentifier());
        sort($identifiers);

        self::assertSame(
            [
                DallEImageGenerator::CONFIGURATION,
                OpenAiSpeechSynthesizer::CONFIGURATION,
            ],
            $identifiers,
        );
        self::assertNotContains(RepurposeConfigurationPresetProvider::TEXT_CONFIGURATION, $identifiers);
    }

    /**
     * The invariant the 500 came from: no identifier may be declared by this
     * provider AND by a pack, because nr-llm publishes both into one registry
     * that refuses duplicates. Asserted across the real pack rather than a
     * fixture, so adding a pack preset that collides fails here.
     */
    public function testNoIdentifierIsDeclaredBothDirectlyAndByAPack(): void
    {
        $direct = array_keys($this->presetsByIdentifier());

        $viaPacks = array_map(
            static fn (UseCasePack $pack): string => $pack->configurationPreset->identifier,
            (new RepurposeStarterPackProvider())->getPacks(),
        );

        self::assertSame([], array_values(array_intersect($direct, $viaPacks)));
    }

    public function testImagePresetIdentifierMatchesTheGeneratorItFeeds(): void
    {
        // The image generator looks the record up by this identifier, so a preset with a
        // different identifier would import a record the generator never reads.
        $preset = $this->presetsByIdentifier()[DallEImageGenerator::CONFIGURATION];

        self::assertSame([ModelCapability::IMAGE->value], $preset->criteria->capabilities);
    }

    public function testSpeechPresetIdentifierMatchesTheGeneratorItFeeds(): void
    {
        $preset = $this->presetsByIdentifier()[OpenAiSpeechSynthesizer::CONFIGURATION];

        self::assertSame([ModelCapability::TEXT_TO_SPEECH->value], $preset->criteria->capabilities);
    }

    public function testTextPresetRequiresChatAndNothingElse(): void
    {
        // JSON_MODE was in here and made the preset unimportable everywhere:
        // no nr-llm model discoverer assigns that capability to any model, so
        // ModelSelectionService found no candidate and the import — and with
        // it the use-case pack that carries this preset — was refused. The
        // pipeline still asks for JSON, per call, through `responseFormat`,
        // which nr-llm passes to the provider without checking a capability.
        // Read from the factory, not from getPresets(): the text preset is the
        // pack's declaration now, and this assertion is about the preset itself.
        $preset = RepurposeConfigurationPresetProvider::textPreset();

        self::assertSame(RepurposeConfigurationPresetProvider::TEXT_CONFIGURATION, $preset->identifier);
        self::assertSame(
            [ModelCapability::CHAT->value],
            $preset->criteria->capabilities,
        );
    }

    public function testEveryPresetCarriesAName(): void
    {
        foreach ($this->presetsByIdentifier() as $preset) {
            self::assertNotSame('', $preset->name);
        }
    }
}
