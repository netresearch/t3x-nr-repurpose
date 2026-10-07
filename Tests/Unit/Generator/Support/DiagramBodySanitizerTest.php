<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Generator\Support;

use Netresearch\NrRepurpose\Generator\Support\DiagramBodySanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiagramBodySanitizer::class)]
final class DiagramBodySanitizerTest extends TestCase
{
    public function testKeepsALayoutOfStyledBlocksListsAndTables(): void
    {
        $html = '<div class="grid" style="display:flex;gap:16px"><section role="group" aria-label="Revenue">'
            . '<h2 style="color:#2F99A4">Revenue</h2><p>Up <strong>12 %</strong> to <em>4.2 m</em><br>in Q1</p>'
            . '<ol start="2"><li>North</li><li>South</li></ol><hr>'
            . '<table><thead><tr><th scope="col">Region</th><th colspan="2">Q1</th></tr></thead>'
            . '<tbody><tr><td rowspan="1">North</td><td>10</td><td>14</td></tr></tbody></table></section></div>';

        self::assertSame($html, DiagramBodySanitizer::sanitize($html));
    }

    /** @return iterable<string, array{string, string}> */
    public static function removedMarkup(): iterable
    {
        yield 'script'           => ['<script>document.title = 1</script><p>kept</p>', '<p>kept</p>'];
        yield 'style element'    => ['<style>p { background: url(https://example.com/x) }</style><p>kept</p>', '<p>kept</p>'];
        yield 'link'             => ['<p>See <a href="https://example.com/">example</a> now</p>', '<p>See  now</p>'];
        yield 'image'            => ['<img src="https://example.com/x.png" alt="x"><p>kept</p>', '<p>kept</p>'];
        yield 'frame'            => ['<iframe src="https://example.com/"></iframe><p>kept</p>', '<p>kept</p>'];
        yield 'svg'              => ['<svg><image href="https://example.com/x.png"/></svg><p>kept</p>', '<p>kept</p>'];
        yield 'form'             => ['<form action="https://example.com/"><input name="q"></form><p>kept</p>', '<p>kept</p>'];
        yield 'meta refresh'     => ['<meta http-equiv="refresh" content="0;url=https://example.com/"><p>kept</p>', '<p>kept</p>'];
        yield 'event handler'    => ['<p onclick="go()" onmouseover="go()">kept</p>', '<p>kept</p>'];
        yield 'unknown attribute' => ['<div data-src="https://example.com/x" srcset="x.png 1x">kept</div>', '<div>kept</div>'];
    }

    #[DataProvider('removedMarkup')]
    public function testRemovesMarkupThatLoadsOrRunsSomething(string $html, string $expected): void
    {
        self::assertSame($expected, DiagramBodySanitizer::sanitize($html));
    }

    public function testKeepsEscapedTextEscaped(): void
    {
        self::assertSame('<p>a &lt;b&gt; &amp; c</p>', DiagramBodySanitizer::sanitize('<p>a &lt;b&gt; &amp; c</p>'));
    }
}
