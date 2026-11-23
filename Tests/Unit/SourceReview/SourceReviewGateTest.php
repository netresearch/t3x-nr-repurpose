<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\SourceReview;

use Netresearch\NrRepurpose\SourceReview\SourceReviewGate;
use PHPUnit\Framework\TestCase;

final class SourceReviewGateTest extends TestCase
{
    public function testInlineMarkupDoesNotSplitTheDisplayedText(): void
    {
        self::assertSame(
            'Growth 1.5% in 2026',
            SourceReviewGate::htmlText('<p>Growth <strong>1.</strong><em>5</em>% in <span>2026</span></p>'),
        );
    }

    public function testBlocksAndCellsStaySeparate(): void
    {
        self::assertSame(
            'Title Revenue 12% Costs 3%',
            preg_replace('/\s+/', ' ', SourceReviewGate::htmlText(
                '<h1>Title</h1><table><tr><td>Revenue</td><td>12%</td></tr><tr><th>Costs</th><td>3%</td></tr></table>',
            )),
        );
    }

    public function testStylesAndScriptsAreLeftOut(): void
    {
        self::assertSame('Visible', SourceReviewGate::htmlText('<style>p{color:red}</style><p>Visible</p><script>x()</script>'));
    }
}
