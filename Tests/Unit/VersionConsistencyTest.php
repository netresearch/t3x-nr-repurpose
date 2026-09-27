<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Guards the hand-maintained version surfaces against drift.
 *
 * Since TYPO3 v14.2 (deprecation #108345) composer.json carries the extension
 * version in extra.typo3/cms.version, next to ext_emconf.php. Once that field
 * and Package.providesPackages are present, classic mode reads composer.json
 * and no longer evaluates ext_emconf.php — so a release bump that forgets one
 * of the two ships metadata that disagrees with itself. The release workflow
 * derives the published version from the git tag and checks neither file.
 */
#[CoversNothing]
final class VersionConsistencyTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function extEmConfVersion(): string
    {
        $contents = file_get_contents($this->repoRoot() . '/ext_emconf.php');
        self::assertIsString($contents, 'ext_emconf.php must be readable');
        self::assertSame(
            1,
            preg_match("/'version'\\s*=>\\s*'([^']+)'/", $contents, $matches),
            'ext_emconf.php must declare a version',
        );

        return $matches[1];
    }

    public function testComposerJsonVersionMatchesExtEmconf(): void
    {
        $composer = json_decode((string) file_get_contents($this->repoRoot() . '/composer.json'), true);
        self::assertIsArray($composer);

        self::assertSame(
            $this->extEmConfVersion(),
            $composer['extra']['typo3/cms']['version'] ?? null,
            'composer.json extra.typo3/cms.version must match the ext_emconf.php version '
            . '(TYPO3 deprecation #108345: bump both on every release).',
        );
    }

    public function testComposerJsonDeclaresProvidesPackages(): void
    {
        $composer = json_decode((string) file_get_contents($this->repoRoot() . '/composer.json'), true);
        self::assertIsArray($composer);

        self::assertIsArray(
            $composer['extra']['typo3/cms']['Package']['providesPackages'] ?? null,
            'composer.json must declare extra.typo3/cms.Package.providesPackages (TYPO3 deprecation #108345).',
        );
    }

    public function testGuidesXmlReleaseMatchesExtEmconf(): void
    {
        $guides = (string) file_get_contents($this->repoRoot() . '/Documentation/guides.xml');

        self::assertStringContainsString(
            'release="' . $this->extEmConfVersion() . '"',
            $guides,
            'Documentation/guides.xml release attribute must match the ext_emconf.php version.',
        );
    }
}
