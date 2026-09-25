<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Provenance;

use Netresearch\NrRepurpose\Generator\Support\TextLabels;
use Netresearch\NrRepurpose\Provenance\AiLabelSettingsFactory;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\MapTextLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Package\Exception\UnknownPackageException;

final class AiLabelSettingsFactoryTest extends TestCase
{
    /**
     * @param array<string, string> $settings setting => stored value; absent = never synchronised
     * @param ?string               $version  null: the version lookup fails
     */
    private function factory(array $settings, ?string $version = '0.7.0'): AiLabelSettingsFactory
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(
            static fn (string $extension, string $path = ''): string => $settings[$path]
                ?? throw new ExtensionConfigurationPathDoesNotExistException('missing ' . $path, 1),
        );

        return new class ($configuration, new MapTextLabels(), $version) extends AiLabelSettingsFactory {
            public function __construct(ExtensionConfiguration $configuration, TextLabels $labels, private readonly ?string $version)
            {
                parent::__construct($configuration, $labels);
            }

            protected function extensionVersion(): string
            {
                return $this->version ?? throw new UnknownPackageException('not loaded', 1);
            }
        };
    }

    /** @return array<string, array{0: array<string, string>, 1: ?string, 2: ?string}> */
    public static function settings(): array
    {
        return [
            'defaults (never synchronised)' => [[], 'KI-generiert', null],
            'both on'                       => [['aiLabelImages' => '1', 'aiLabelTexts' => '1'], 'KI-generiert', 'Dieser Text wurde mit KI erstellt.'],
            'both off'                      => [['aiLabelImages' => '0', 'aiLabelTexts' => '0'], null, null],
        ];
    }

    /** @param array<string, string> $settings */
    #[DataProvider('settings')]
    public function testSettingsDecideTheVisibleLabels(array $settings, ?string $imageLabel, ?string $textLine): void
    {
        $label = $this->factory($settings)->create('de');

        self::assertSame($imageLabel, $label->imageLabel);
        self::assertSame($textLine, $label->textLine);
    }

    public function testLabelsFollowTheArtifactLanguage(): void
    {
        $label = $this->factory(['aiLabelImages' => '1', 'aiLabelTexts' => '1'])->create('en');

        self::assertSame('AI-generated', $label->imageLabel);
        self::assertSame('This text was created with AI.', $label->textLine);
    }

    public function testGeneratorCarriesTheInstalledVersion(): void
    {
        self::assertSame('nr_repurpose 0.7.0', $this->factory([])->create('en')->generator);
    }

    public function testGeneratorWithoutAKnownVersionIsTheExtensionKey(): void
    {
        self::assertSame('nr_repurpose', $this->factory([], '')->create('en')->generator);
        self::assertSame('nr_repurpose', $this->factory([], null)->create('en')->generator);
    }
}
