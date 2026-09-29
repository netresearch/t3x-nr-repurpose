<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The renderer has no network access, so every Google Fonts family a Generated/* template.
 *
 * @imports must be bundled in Resources/Private/Fonts; render.cjs swaps the @import for
 * these files. A family missing here would silently render in a fallback font.
 */
final class BundledFontsTest extends TestCase
{
    private const string FONT_DIR = __DIR__ . '/../../../Resources/Private/Fonts';

    private const string TEMPLATE_DIR = __DIR__ . '/../../../Resources/Private/Templates/Generated';

    /** @return array<string, array{file: string, sha256: string, weight: string, stretch: string, variationSettings?: string}> */
    private function families(): array
    {
        $manifest = json_decode((string) file_get_contents(self::FONT_DIR . '/fonts.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['families'] ?? null);

        return $manifest['families'];
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        foreach (glob(self::TEMPLATE_DIR . '/*/*.html') ?: [] as $path) {
            yield basename(dirname($path)) . '/' . basename($path) => [$path];
        }
    }

    #[DataProvider('templates')]
    public function testEveryGoogleFontsFamilyATemplateRequestsIsBundled(string $template): void
    {
        $html = (string) file_get_contents($template);
        preg_match_all('#https://fonts\.googleapis\.com/css2?\?([^\'")\s]+)#', $html, $matches);

        $requested = [];
        foreach ($matches[1] as $query) {
            foreach (explode('&', str_replace('&amp;', '&', $query)) as $pair) {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                if ($key !== 'family') {
                    continue;
                }

                foreach (explode('|', urldecode($value)) as $family) {
                    $requested[] = explode(':', $family, 2)[0];
                }
            }
        }

        $missing = array_diff($requested, array_keys($this->families()));
        self::assertSame([], array_values($missing), 'not bundled in Resources/Private/Fonts/fonts.json');
    }

    public function testTheTemplatesRequestTheThreeBundledFamilies(): void
    {
        // Guards the parser above: if it found nothing, the test per template would pass vacuously.
        $all = '';
        foreach (self::templates() as [$template]) {
            $all .= file_get_contents($template);
        }

        foreach (['family=Raleway', 'family=Open+Sans', 'family=Inter'] as $needle) {
            self::assertStringContainsString($needle, $all);
        }
    }

    /**
     * The OFL reserves the font names for unmodified files, so the bundled files must stay
     * byte-identical to upstream, and each ships with its licence.
     */
    public function testEveryBundledFileIsTheRecordedUpstreamFileWithItsLicence(): void
    {
        foreach ($this->families() as $family => $font) {
            $path = self::FONT_DIR . '/' . $font['file'];
            self::assertFileExists($path, $family);
            self::assertSame($font['sha256'], hash_file('sha256', $path), $family . ' differs from the recorded upstream file');
            self::assertFileExists(dirname($path) . '/OFL.txt', $family . ' ships without its licence');
            self::assertMatchesRegularExpression('/^\d+( \d+)?$/', $font['weight'], $family);
            self::assertMatchesRegularExpression('/^\d+%( \d+%)?$/', $font['stretch'], $family);
            if (isset($font['variationSettings'])) {
                self::assertMatchesRegularExpression("/^'[a-z]{4}' \\d+(, '[a-z]{4}' \\d+)*$/", $font['variationSettings'], $family);
            }
        }
    }
}
