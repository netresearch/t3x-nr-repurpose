<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Configuration;

use BackedEnum;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\Enum\PublishStatus;
use Netresearch\NrRepurpose\Domain\Enum\ReviewStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use SplFileInfo;

/**
 * Every label reference of the extension resolves to an English source and a German
 * target: the literal LLL references in the code, the configuration and the templates, and
 * the label keys the status and type enums hand to the templates. TYPO3 falls back to the
 * English text when a German target is missing, so only the language files themselves show
 * a gap; Tests/Functional/Configuration/LabelResolutionTest resolves the same references
 * through TYPO3.
 */
final class LabelReferencesTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    private const string LANGUAGE_DIR = self::ROOT . '/Resources/Private/Language/';

    /** A literal reference; a key with a Fluid variable in it (`{...}`) does not match. */
    private const string LLL = '~LLL:EXT:nr_repurpose/Resources/Private/Language/([a-z_]+\.xlf):([A-Za-z0-9_.-]+)(?![A-Za-z0-9_.{-])~';

    /** @var array<string, SimpleXMLElement> */
    private array $loaded = [];

    public function testEveryExtensionSettingLabelIsALanguageReference(): void
    {
        $template = (string) file_get_contents(self::ROOT . '/ext_conf_template.txt');
        self::assertSame(6, preg_match_all('/label=(\S+)$/m', $template, $matches));
        foreach ($matches[1] as $label) {
            self::assertMatchesRegularExpression(self::LLL, $label);
        }
    }

    /**
     * The files whose labels moved into the language files must keep referencing them; a
     * pattern that matches nothing there would let the resolution test below pass empty.
     *
     * @return array<string, array{string}>
     */
    public static function movedLabelFiles(): array
    {
        return [
            'job TCA'            => ['Configuration/TCA/tx_nrrepurpose_domain_model_job.php'],
            'artifact TCA'       => ['Configuration/TCA/tx_nrrepurpose_domain_model_artifact.php'],
            'permission options' => ['ext_localconf.php'],
            'extension settings' => ['ext_conf_template.txt'],
        ];
    }

    #[DataProvider('movedLabelFiles')]
    public function testTheMovedLabelFilesReferenceTheLanguageFiles(string $file): void
    {
        self::assertArrayHasKey($file, $this->references());
    }

    public function testEveryLiteralReferenceHasAnEnglishSourceAndAGermanTarget(): void
    {
        $count = 0;
        foreach ($this->references() as $file => $references) {
            foreach ($references as [$languageFile, $id]) {
                $this->assertTranslated($languageFile, $id, $file);
                ++$count;
            }
        }

        // The templates alone hold well over a hundred; a broken pattern finds far fewer.
        self::assertGreaterThan(150, $count);
    }

    /**
     * The templates translate `locallang.xlf:{….labelKey}` for these enums.
     *
     * @return array<string, array{list<BackedEnum>}>
     */
    public static function labelledEnums(): array
    {
        return [
            'JobStatus'      => [JobStatus::cases()],
            'ArtifactStatus' => [ArtifactStatus::cases()],
            'ArtifactType'   => [ArtifactType::cases()],
            'ReviewStatus'   => [ReviewStatus::cases()],
            'PublishStatus'  => [PublishStatus::cases()],
        ];
    }

    /** @param list<BackedEnum> $cases */
    #[DataProvider('labelledEnums')]
    public function testEveryEnumLabelKeyIsTranslated(array $cases): void
    {
        foreach ($cases as $case) {
            self::assertTrue(method_exists($case, 'getLabelKey'));
            $this->assertTranslated('locallang.xlf', (string) $case->getLabelKey(), $case::class . '::' . $case->name);
        }
    }

    /** @return array<string, array{string}> */
    public static function languageFiles(): array
    {
        $files = [];
        foreach (glob(self::LANGUAGE_DIR . 'locallang*.xlf') ?: [] as $path) {
            $files[basename($path)] = [basename($path)];
        }

        return $files;
    }

    /**
     * Each English file has a German twin with the same units and the same sources, so a
     * text changed on one side only shows here, and every German target is filled.
     */
    #[DataProvider('languageFiles')]
    public function testEveryLanguageFileHasAMatchingGermanTwin(string $file): void
    {
        $english = $this->units($file);
        $german  = $this->units('de.' . $file);

        self::assertNotSame([], $english, $file);
        self::assertSame(array_keys($english), array_keys($german), 'de.' . $file . ': unit ids');
        foreach ($english as $id => $unit) {
            self::assertNotSame('', trim((string) $unit->source), $file . ':' . $id);
            self::assertSame((string) $unit->source, (string) $german[$id]->source, 'de source: ' . $file . ':' . $id);
            self::assertNotSame('', trim((string) $german[$id]->target), 'de target: ' . $file . ':' . $id);
        }
    }

    /**
     * The settings form splits the translated label at its first colon into the title
     * and the description (AstConstantCommentVisitor), in both languages.
     */
    public function testExtensionSettingLabelsKeepTheTitleColonShape(): void
    {
        foreach (['locallang_em.xlf', 'de.locallang_em.xlf'] as $file) {
            foreach ($this->units($file) as $id => $unit) {
                $text = $file === 'locallang_em.xlf' ? (string) $unit->source : (string) $unit->target;
                self::assertMatchesRegularExpression('/^[^:]{3,80}: \S/', $text, $file . ':' . $id);
            }
        }
    }

    private function assertTranslated(string $languageFile, string $id, string $context): void
    {
        $reference = $languageFile . ':' . $id . ' (' . $context . ')';
        $source    = $this->units($languageFile)[$id] ?? null;
        self::assertNotNull($source, $reference);
        self::assertNotSame('', trim((string) $source->source), $reference);

        $german = $this->units('de.' . $languageFile)[$id] ?? null;
        self::assertNotNull($german, 'de: ' . $reference);
        self::assertNotSame('', trim((string) $german->target), 'de target: ' . $reference);
    }

    /**
     * The literal references per file, below Classes, Configuration and
     * Resources/Private plus the ext_* files.
     *
     * @return array<string, list<array{string, string}>>
     */
    private function references(): array
    {
        $paths = [self::ROOT . '/ext_localconf.php', self::ROOT . '/ext_conf_template.txt'];
        foreach (['Classes', 'Configuration', 'Resources/Private'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT . '/' . $dir));
            /** @var SplFileInfo $info */
            foreach ($files as $info) {
                if ($info->isFile() && in_array($info->getExtension(), ['php', 'html'], true)) {
                    $paths[] = $info->getPathname();
                }
            }
        }

        $references = [];
        foreach ($paths as $path) {
            if (preg_match_all(self::LLL, (string) file_get_contents($path), $matches, PREG_SET_ORDER) > 0) {
                $relative              = substr($path, strlen(self::ROOT) + 1);
                $references[$relative] = array_map(static fn (array $match): array => [$match[1], $match[2]], $matches);
            }
        }

        return $references;
    }

    /** @return array<string, SimpleXMLElement> trans-units by id */
    private function units(string $file): array
    {
        if (!isset($this->loaded[$file])) {
            $xml = simplexml_load_file(self::LANGUAGE_DIR . $file);
            self::assertInstanceOf(SimpleXMLElement::class, $xml, $file);
            $this->loaded[$file] = $xml;
        }

        $units = [];
        foreach ($this->loaded[$file]->file->body->{'trans-unit'} as $unit) {
            $units[(string) $unit['id']] = $unit;
        }

        return $units;
    }
}
