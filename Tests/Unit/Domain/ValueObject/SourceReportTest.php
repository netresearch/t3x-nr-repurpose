<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\ValueObject;

use Netresearch\NrRepurpose\Domain\ValueObject\SourceReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceReport::class)]
final class SourceReportTest extends TestCase
{
    #[Test]
    public function aReportSurvivesTheColumn(): void
    {
        $report = new SourceReport(3, 1, 2, 4, 5, 6, 7, true, true, 25, 1000);

        self::assertEquals($report, SourceReport::fromJson($report->toJson()));
    }

    /** @return array<string, array{string}> */
    public static function unusableColumns(): array
    {
        return [
            'a job without a report' => [''],
            'broken JSON'            => ['{'],
            'not an object'          => ['7'],
        ];
    }

    #[Test]
    #[DataProvider('unusableColumns')]
    public function anUnusableColumnIsAnEmptyReport(string $json): void
    {
        self::assertEquals(new SourceReport(), SourceReport::fromJson($json));
    }

    #[Test]
    public function aValueOfTheWrongTypeCountsAsZero(): void
    {
        self::assertSame(0, SourceReport::fromJson('{"pagesUsed":"many"}')->pagesUsed);
    }

    /** @return array<string, array{SourceReport, bool}> */
    public static function reports(): array
    {
        return [
            'all pages read'   => [new SourceReport(pagesUsed: 3), false],
            'page limit'       => [new SourceReport(cutByPageLimit: true), true],
            'text limit'       => [new SourceReport(cutByTextLimit: true), true],
            'a failed page'    => [new SourceReport(pagesFailed: 1), true],
            'a hidden page'    => [new SourceReport(skippedHidden: 1), true],
            'a restricted one' => [new SourceReport(skippedRestricted: 1), true],
            'a folder'         => [new SourceReport(skippedExcluded: 1), true],
            'no access'        => [new SourceReport(skippedNoAccess: 1), true],
        ];
    }

    #[Test]
    #[DataProvider('reports')]
    public function hasOmissionsAnswersWhetherAPageIsMissing(SourceReport $report, bool $expected): void
    {
        self::assertSame($expected, $report->hasOmissions());
    }
}
