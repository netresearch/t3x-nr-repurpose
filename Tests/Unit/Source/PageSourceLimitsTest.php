<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Source;

use Netresearch\NrRepurpose\Source\PageSourceLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(PageSourceLimits::class)]
final class PageSourceLimitsTest extends TestCase
{
    /** @return array<string, array{mixed, int}> */
    public static function values(): array
    {
        return [
            'a number'        => ['7', 7],
            'zero'            => ['0', PageSourceLimits::DEFAULT_MAX_PAGES],
            'below zero'      => ['-3', PageSourceLimits::DEFAULT_MAX_PAGES],
            'not a number'    => ['many', PageSourceLimits::DEFAULT_MAX_PAGES],
            'nothing set yet' => [null, PageSourceLimits::DEFAULT_MAX_PAGES],
        ];
    }

    #[Test]
    #[DataProvider('values')]
    public function maxPagesReadsTheSetting(mixed $value, int $expected): void
    {
        self::assertSame($expected, $this->limits($value)->maxPages());
    }

    #[Test]
    public function anInstallationFromBeforeTheSettingGetsTheDefaults(): void
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException(new RuntimeException('no such path'));

        $limits = new PageSourceLimits($configuration);

        self::assertSame(PageSourceLimits::DEFAULT_MAX_PAGES, $limits->maxPages());
        self::assertSame(PageSourceLimits::DEFAULT_MAX_CHARS, $limits->maxChars());
    }

    /** Above this the analysis refuses the text, so a cut selection would fail there. */
    #[Test]
    public function maxCharsStaysBelowWhatTheAnalysisAccepts(): void
    {
        self::assertSame(PageSourceLimits::HARD_MAX_CHARS, $this->limits('5000000')->maxChars());
        self::assertLessThan(100 * 12000, PageSourceLimits::HARD_MAX_CHARS);
    }

    private function limits(mixed $value): PageSourceLimits
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn($value);

        return new PageSourceLimits($configuration);
    }
}
