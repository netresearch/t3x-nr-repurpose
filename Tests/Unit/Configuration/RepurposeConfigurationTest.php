<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Configuration;

use Netresearch\NrRepurpose\Configuration\RepurposeConfiguration;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class RepurposeConfigurationTest extends TestCase
{
    /** @param array<string,mixed> $tree */
    private function withTree(array $tree): RepurposeConfiguration
    {
        $extConf = $this->createMock(ExtensionConfiguration::class);
        $extConf->method('get')->with('nr_repurpose')->willReturn($tree);

        return new RepurposeConfiguration($extConf);
    }

    public function testDefaultsApplyWhenConfigIsEmpty(): void
    {
        $config = $this->withTree([]);

        self::assertSame('nova', $config->hostAVoice());
        self::assertSame('onyx', $config->hostBVoice());
        self::assertSame('tts-1-hd', $config->ttsModel());
        self::assertSame('dalle', $config->imageProvider());
        self::assertSame('dall-e-3', $config->imageModel());
        self::assertSame(1200, $config->diagramViewportWidth());
        self::assertSame(1080, $config->storyWidth());
        self::assertSame(1920, $config->storyHeight());
        self::assertSame('nr', $config->defaultTheme());
        self::assertSame(12000, $config->mapReduceCharThreshold());
    }

    public function testConfiguredValuesOverrideDefaultsAndAreCoerced(): void
    {
        $config = $this->withTree([
            'voices' => ['hostA' => 'alloy', 'hostB' => 'shimmer'],
            'tts' => ['model' => 'tts-1'],
            'image' => ['provider' => 'dalle', 'model' => 'dall-e-3'],
            'diagram' => ['viewportWidth' => '1600'],
            'story' => ['width' => '1080', 'height' => '1920'],
            'defaultTheme' => 'neutral',
            'mapReduce' => ['charThreshold' => '8000'],
        ]);

        self::assertSame('alloy', $config->hostAVoice());
        self::assertSame('shimmer', $config->hostBVoice());
        self::assertSame('tts-1', $config->ttsModel());
        self::assertSame('dalle', $config->imageProvider());
        self::assertSame('dall-e-3', $config->imageModel());
        self::assertSame(1600, $config->diagramViewportWidth());
        self::assertSame('neutral', $config->defaultTheme());
        self::assertSame(8000, $config->mapReduceCharThreshold());
    }

    public function testUnknownThemeFallsBackToNr(): void
    {
        $config = $this->withTree(['defaultTheme' => 'rainbow']);

        self::assertSame('nr', $config->defaultTheme());
    }
}
