<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The backend labels of the TCA, the permission options and the extension settings come
 * from the language files, and every reference has an English source and a German target.
 */
final class LabelReferencesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const LLL = '~LLL:EXT:nr_repurpose/Resources/Private/Language/([a-z_]+\.xlf):([A-Za-z0-9_.]+)~';

    /** @return array<string, array{string}> */
    public static function tcaFiles(): array
    {
        return [
            'job'      => ['Configuration/TCA/tx_nrrepurpose_domain_model_job.php'],
            'artifact' => ['Configuration/TCA/tx_nrrepurpose_domain_model_artifact.php'],
        ];
    }

    #[DataProvider('tcaFiles')]
    public function testEveryTcaTitleAndLabelIsALanguageReference(string $file): void
    {
        /** @var array{ctrl: array<string, mixed>, columns: array<string, array<string, mixed>>} $tca */
        $tca     = require self::ROOT . '/' . $file;
        $strings = [(string) $tca['ctrl']['title']];
        foreach ($tca['columns'] as $column) {
            if (isset($column['label'])) {
                $strings[] = (string) $column['label'];
            }

            foreach ($column['config']['items'] ?? [] as $item) {
                $strings[] = (string) $item['label'];
            }
        }

        foreach ($strings as $string) {
            self::assertMatchesRegularExpression(self::LLL, $string);
        }
    }

    #[DataProvider('tcaFiles')]
    public function testTheRecordIconIsARegisteredIdentifier(string $file): void
    {
        /** @var array{ctrl: array<string, mixed>} $tca */
        $tca = require self::ROOT . '/' . $file;
        /** @var array<string, mixed> $icons */
        $icons = require self::ROOT . '/Configuration/Icons.php';

        self::assertArrayNotHasKey('iconfile', $tca['ctrl']);
        self::assertArrayHasKey((string) $tca['ctrl']['typeicon_classes']['default'], $icons);
    }

    public function testEveryExtensionSettingLabelIsALanguageReference(): void
    {
        $template = (string) file_get_contents(self::ROOT . '/ext_conf_template.txt');
        self::assertSame(5, preg_match_all('/label=(\S+)$/m', $template, $matches));
        foreach ($matches[1] as $label) {
            self::assertMatchesRegularExpression(self::LLL, $label);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function referencingFiles(): array
    {
        return [
            'job TCA'            => ['Configuration/TCA/tx_nrrepurpose_domain_model_job.php'],
            'artifact TCA'       => ['Configuration/TCA/tx_nrrepurpose_domain_model_artifact.php'],
            'permission options' => ['ext_localconf.php'],
            'extension settings' => ['ext_conf_template.txt'],
        ];
    }

    #[DataProvider('referencingFiles')]
    public function testEveryReferenceHasAnEnglishSourceAndAGermanTarget(string $file): void
    {
        $content = (string) file_get_contents(self::ROOT . '/' . $file);
        self::assertGreaterThan(0, preg_match_all(self::LLL, $content, $matches, PREG_SET_ORDER));

        foreach ($matches as [$reference, $languageFile, $id]) {
            $source = $this->unit($languageFile, $id);
            self::assertNotNull($source, $reference);
            self::assertNotSame('', trim((string) $source->source), $reference);

            $german = $this->unit('de.' . $languageFile, $id);
            self::assertNotNull($german, 'de: ' . $reference);
            self::assertSame((string) $source->source, (string) $german->source, 'de source: ' . $reference);
            self::assertNotSame('', trim((string) $german->target), 'de target: ' . $reference);
        }
    }

    /**
     * The settings form splits the translated label at its first colon into the title
     * and the description (AstConstantCommentVisitor), in both languages.
     */
    public function testExtensionSettingLabelsKeepTheTitleColonShape(): void
    {
        foreach (['locallang_em.xlf', 'de.locallang_em.xlf'] as $file) {
            $xliff = $this->load($file);
            foreach ($xliff->file->body->{'trans-unit'} as $unit) {
                $text = $file === 'locallang_em.xlf' ? (string) $unit->source : (string) $unit->target;
                self::assertMatchesRegularExpression('/^[^:]{3,80}: \S/', $text, $file . ':' . $unit['id']);
            }
        }
    }

    private function unit(string $file, string $id): ?SimpleXMLElement
    {
        foreach ($this->load($file)->file->body->{'trans-unit'} as $unit) {
            if ((string) $unit['id'] === $id) {
                return $unit;
            }
        }

        return null;
    }

    private function load(string $file): SimpleXMLElement
    {
        $xml = simplexml_load_file(self::ROOT . '/Resources/Private/Language/' . $file);
        self::assertInstanceOf(SimpleXMLElement::class, $xml, $file);

        return $xml;
    }
}
