<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Service\Preset;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrRepurpose\Generator\Image\DallEImageGenerator;
use Netresearch\NrRepurpose\Generator\Speech\OpenAiSpeechSynthesizer;
use Netresearch\NrRepurpose\Service\Preset\RepurposeConfigurationPresetProvider;
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

    public function testDeclaresTextImageAndSpeechPresets(): void
    {
        $identifiers = array_keys($this->presetsByIdentifier());
        sort($identifiers);

        // Sorted alphabetically: nr_repurpose_image, nr_repurpose_text, nr_repurpose_tts.
        self::assertSame(
            [
                DallEImageGenerator::CONFIGURATION,
                RepurposeConfigurationPresetProvider::TEXT_CONFIGURATION,
                OpenAiSpeechSynthesizer::CONFIGURATION,
            ],
            $identifiers,
        );
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

    public function testTextPresetRequiresChatAndJsonMode(): void
    {
        $preset = $this->presetsByIdentifier()[RepurposeConfigurationPresetProvider::TEXT_CONFIGURATION];

        self::assertSame(
            [ModelCapability::CHAT->value, ModelCapability::JSON_MODE->value],
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
