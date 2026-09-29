<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Configuration;

use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The record forms as TYPO3 loads them: every title, field label and select item of the
 * two tables is a language reference that resolves in English and German, and the record
 * icon is a registered identifier. The language files themselves (German targets, matching
 * units) are checked in Tests/Unit/Configuration/LabelReferencesTest.
 */
final class LabelResolutionTest extends AbstractFunctionalTestCase
{
    /** @return array<string, array{string}> */
    public static function tables(): array
    {
        return [
            'job'      => ['tx_nrrepurpose_domain_model_job'],
            'artifact' => ['tx_nrrepurpose_domain_model_artifact'],
        ];
    }

    #[DataProvider('tables')]
    public function testEveryTitleLabelAndItemResolvesInEnglishAndGerman(string $table): void
    {
        /** @var array{ctrl: array<string, mixed>, columns: array<string, array<string, mixed>>} $tca */
        $tca     = $GLOBALS['TCA'][$table];
        $strings = [(string) $tca['ctrl']['title']];
        foreach ($tca['columns'] as $column) {
            if (isset($column['label'])) {
                $strings[] = (string) $column['label'];
            }

            foreach ($column['config']['items'] ?? [] as $item) {
                $strings[] = (string) $item['label'];
            }
        }

        $factory = $this->get(LanguageServiceFactory::class);
        $english = $factory->create('default');
        $german  = $factory->create('de');
        foreach ($strings as $string) {
            self::assertStringStartsWith('LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:', $string);
            self::assertNotSame('', $english->sL($string), $string);
            self::assertNotSame('', $german->sL($string), 'de: ' . $string);
        }
    }

    #[DataProvider('tables')]
    public function testTheRecordIconIsARegisteredIdentifier(string $table): void
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'];

        self::assertArrayNotHasKey('iconfile', $ctrl);
        self::assertTrue($this->get(IconRegistry::class)->isRegistered((string) $ctrl['typeicon_classes']['default']));
    }

    public function testThePermissionDescriptionsResolveInGerman(): void
    {
        $german = $this->get(LanguageServiceFactory::class)->create('de');
        foreach ($GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['nrrepurpose']['items'] as $name => $item) {
            self::assertStringStartsWith('LLL:EXT:nr_repurpose/', (string) $item[2], $name);
            self::assertNotSame('', $german->sL((string) $item[2]), $name);
        }
    }
}
