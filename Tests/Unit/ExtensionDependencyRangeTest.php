<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * composer.json and ext_emconf.php state the same dependency ranges.
 *
 * A composer install reads composer.json, an Extension Manager or TER install reads
 * ext_emconf.php. When a dependency update widens only composer.json (Renovate edits
 * that file alone), the two install paths accept different versions; nr-vault ^0.16
 * was allowed by composer and refused by ext_emconf.php this way.
 */
final class ExtensionDependencyRangeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /** Composer requirement => ext_emconf.php depends key, for PHP and every extension this one depends on. */
    private const EXTENSIONS = [
        'php'                  => 'php',
        'typo3/cms-core'       => 'typo3',
        'netresearch/nr-llm'   => 'nr_llm',
        'netresearch/nr-vault' => 'nr_vault',
    ];

    /** @return array<string, array{0: string, 1: string}> */
    public static function dependencies(): array
    {
        $cases = [];
        foreach (self::EXTENSIONS as $package => $extensionKey) {
            $cases[$extensionKey] = [$package, $extensionKey];
        }

        return $cases;
    }

    #[DataProvider('dependencies')]
    public function testEmconfRangeMatchesComposerConstraint(string $package, string $extensionKey): void
    {
        $require = $this->composer()['require'] ?? [];
        self::assertIsArray($require);
        self::assertArrayHasKey($package, $require, $package . ' is not required in composer.json');
        self::assertIsString($require[$package]);

        $depends = $this->emconf()['constraints']['depends'] ?? [];
        self::assertArrayHasKey($extensionKey, $depends, $extensionKey . ' is missing from ext_emconf.php constraints.depends');

        self::assertSame($this->emconfRange($require[$package]), $depends[$extensionKey], $package . ' ' . $require[$package]);
    }

    public function testEveryEmconfDependencyIsCompared(): void
    {
        $depends = $this->emconf()['constraints']['depends'] ?? [];

        // A dependency added to ext_emconf.php without an entry above would not be compared at all.
        self::assertSame([], array_values(array_diff(array_keys($depends), array_values(self::EXTENSIONS))));
    }

    public function testEveryRequiredNetresearchPackageIsCompared(): void
    {
        $require = $this->composer()['require'] ?? [];
        self::assertIsArray($require);
        $netresearch = array_filter(array_keys($require), static fn (string $name): bool => str_starts_with($name, 'netresearch/'));

        self::assertSame([], array_values(array_diff($netresearch, array_keys(self::EXTENSIONS))));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function conversions(): array
    {
        return [
            'one minor of a 0.x line' => ['^0.15', '0.15.0-0.15.99'],
            'consecutive 0.x minors'  => ['^0.15 || ^0.16', '0.15.0-0.16.99'],
            'four 0.x minors'         => ['^0.35 || ^0.36 || ^0.37 || ^0.38', '0.35.0-0.38.99'],
            'a major from a minor on' => ['^14.3', '14.3.0-14.99.99'],
            'php'                     => ['^8.3', '8.3.0-8.99.99'],
        ];
    }

    #[DataProvider('conversions')]
    public function testTheConversionItself(string $constraint, string $range): void
    {
        self::assertSame($range, $this->emconfRange($constraint));
    }

    /**
     * The ext_emconf.php range a caret constraint (or a contiguous || list of them) allows.
     * Anything else fails loudly instead of being compared approximately.
     */
    private function emconfRange(string $constraint): string
    {
        $bounds = [];
        foreach (preg_split('/\s*\|\|\s*/', trim($constraint)) ?: [] as $part) {
            self::assertSame(1, preg_match('/^\^(\d+)\.(\d+)$/', $part, $m), 'unsupported constraint: ' . $constraint);
            $bounds[] = [(int) $m[1], (int) $m[2]];
        }

        sort($bounds);
        [$lowMajor, $lowMinor]   = $bounds[0];
        [$highMajor, $highMinor] = $bounds[count($bounds) - 1];

        if ($lowMajor === 0) {
            self::assertSame(0, $highMajor, 'mixed 0.x and stable constraint: ' . $constraint);
            self::assertSame(range($lowMinor, $highMinor), array_column($bounds, 1), 'gap in 0.x minors: ' . $constraint);

            return sprintf('0.%d.0-0.%d.99', $lowMinor, $highMinor);
        }

        self::assertCount(1, $bounds, 'more than one stable caret: ' . $constraint);

        return sprintf('%d.%d.0-%d.99.99', $lowMajor, $lowMinor, $lowMajor);
    }

    /** @return array<string, mixed> */
    private function composer(): array
    {
        $composer = json_decode((string) file_get_contents(self::ROOT . 'composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        return $composer;
    }

    /**
     * constraints.depends of ext_emconf.php, read from the PHP tokens without executing the file.
     * Executing it would need `require` per test (the file only assigns the global $EM_CONF, so
     * `require_once` leaves every later test with nothing). Reading tokens instead of text skips
     * comments, so a commented-out or trailing range in a comment is not mistaken for the real one,
     * and accepts single- and double-quoted strings alike.
     *
     * @return array{constraints: array{depends: array<string, string>}}
     */
    private function emconf(): array
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents(self::ROOT . 'ext_emconf.php')),
            static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
        ));

        $depends = [];
        $count   = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            if ($this->literal($tokens[$i]) !== 'depends' || ($tokens[$i + 1][0] ?? null) !== T_DOUBLE_ARROW || ($tokens[$i + 2] ?? null) !== '[') {
                continue;
            }

            self::assertSame([], $depends, 'more than one depends block in ext_emconf.php');
            for ($j = $i + 3; $j < $count && $tokens[$j] !== ']'; ++$j) {
                $key = $this->literal($tokens[$j]);
                if ($key === null || ($tokens[$j + 1][0] ?? null) !== T_DOUBLE_ARROW) {
                    continue;
                }

                $range = $this->literal($tokens[$j + 2] ?? '');
                self::assertIsString($range, 'depends.' . $key . ' is not a string literal');
                self::assertArrayNotHasKey($key, $depends, 'depends.' . $key . ' is declared twice');
                $depends[$key] = $range;
            }
        }

        self::assertNotSame([], $depends, 'no depends entries found in ext_emconf.php');

        return ['constraints' => ['depends' => $depends]];
    }

    /** The value of a plain string literal token ('…' or "…" without interpolation), else null. */
    private function literal(array|string $token): ?string
    {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return stripcslashes(substr($token[1], 1, -1));
    }
}
