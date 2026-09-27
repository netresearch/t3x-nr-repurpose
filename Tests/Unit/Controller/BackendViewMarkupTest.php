<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Controller;

use function assert;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The backend module's markup follows the core backend: tables use the core table and
 * column classes, a long source URL is cut to one line instead of widening the table,
 * and no template carries inline layout styles (they live in Resources/Public/Css/backend.css,
 * built on the core custom properties, so the dark scheme applies).
 *
 * The templates are read as HTML: the Fluid tags are unknown elements to the parser,
 * the attributes under test are plain HTML attributes.
 */
final class BackendViewMarkupTest extends TestCase
{
    private const RESOURCES = __DIR__ . '/../../../Resources/';

    /** @return array<string, array{0: string}> */
    public static function backendTemplates(): array
    {
        return [
            'job list'              => ['Private/Templates/Job/List.html'],
            'new job'               => ['Private/Templates/Job/New.html'],
            'job detail'            => ['Private/Templates/Job/Show.html'],
            'social planning'       => ['Private/Templates/Job/Plan.html'],
            'review partial'        => ['Private/Partials/Job/Review.html'],
            'text artifact partial' => ['Private/Partials/Job/TextArtifact.html'],
            'parameters partial'    => ['Private/Partials/Job/GenerationParameters.html'],
            'ai label partial'      => ['Private/Partials/Job/AiLabel.html'],
            'artifacts partial'     => ['Private/Partials/Job/ArtifactSummaries.html'],
        ];
    }

    #[DataProvider('backendTemplates')]
    public function testNoInlineStylesExceptTheProgressValue(string $template): void
    {
        $styled = [];
        foreach ($this->xpath($template)->query('//*[@style]') ?: [] as $element) {
            assert($element instanceof DOMElement);
            // The progress bar width is the job's value, not layout: the one inline style kept.
            if ($element->getAttribute('class') === 'progress-bar' && $element->getAttribute('style') === 'width: {job.progress}%;') {
                continue;
            }

            $styled[] = $element->nodeName . ' style="' . $element->getAttribute('style') . '"';
        }

        self::assertSame([], $styled);
    }

    /** @return array<string, array{0: string}> */
    public static function tableTemplates(): array
    {
        return [
            'job list'        => ['Private/Templates/Job/List.html'],
            'social planning' => ['Private/Templates/Job/Plan.html'],
        ];
    }

    #[DataProvider('tableTemplates')]
    public function testTablesUseTheCoreTableMarkup(string $template): void
    {
        $xpath  = $this->xpath($template);
        $tables = $xpath->query('//table');
        self::assertNotFalse($tables);
        self::assertGreaterThan(0, $tables->length);

        foreach ($tables as $table) {
            assert($table instanceof DOMElement);
            self::assertSame('table table-striped table-hover', $this->classes($table, ['table', 'table-striped', 'table-hover']));
            $parent = $table->parentNode;
            self::assertInstanceOf(DOMElement::class, $parent);
            self::assertSame('table-fit', $parent->getAttribute('class'));

            // Named by the page heading, so a screen reader announces which table it enters.
            $labelledBy = $table->getAttribute('aria-labelledby');
            self::assertNotSame('', $labelledBy);
            self::assertSame(1, $xpath->query('//h1[@id="' . $labelledBy . '"]')?->length);

            foreach ($xpath->query('.//thead/tr/th', $table) ?: [] as $th) {
                assert($th instanceof DOMElement);
                self::assertSame('col', $th->getAttribute('scope'));
            }
        }
    }

    public function testTheListCutsTheSourceToOneLineAndKeepsTheFullValue(): void
    {
        $cells = $this->xpath('Private/Templates/Job/List.html')->query('//td[contains(., "{job.sourceValue}")]');
        self::assertNotFalse($cells);
        self::assertSame(1, $cells->length);
        $cell = $cells->item(0);
        assert($cell instanceof DOMElement);

        // Core .col-responsive: one line, ellipsis, max 200px (TYPO3 13.4 and 14.3).
        self::assertStringContainsString('col-responsive', $cell->getAttribute('class'));
        // Hover shows the whole URL; the cell text itself stays complete for copying and screen readers.
        self::assertSame('{job.sourceValue}', $cell->getAttribute('title'));
        self::assertSame('{job.sourceValue}', trim($cell->textContent));
    }

    public function testThePlanCutsTheSourceToOneLineAndKeepsTheFullValue(): void
    {
        $cells = $this->xpath('Private/Templates/Job/Plan.html')->query('//td[contains(., "{post.source_value}")]');
        self::assertNotFalse($cells);
        self::assertSame(1, $cells->length);
        $cell = $cells->item(0);
        assert($cell instanceof DOMElement);

        self::assertStringContainsString('col-responsive', $cell->getAttribute('class'));
        self::assertSame('{post.source_value}', $cell->getAttribute('title'));
    }

    public function testTheListUsesTheCoreColumnClasses(): void
    {
        $xpath = $this->xpath('Private/Templates/Job/List.html');

        self::assertSame(1, $xpath->query('//td[@class="col-progress"]/div[contains(@class, "progress")]')?->length, 'progress column');
        self::assertSame(1, $xpath->query('//td[@class="col-nowrap"]/*[name()="f:render"][@partial="Job/ArtifactSummaries"]')?->length, 'artifact icons stay on one line');
        self::assertSame(1, $xpath->query('//th[@class="col-control"]')?->length, 'action column header');
        self::assertSame(1, $xpath->query('//td[@class="col-control"]')?->length, 'action column cell');
    }

    public function testTheDetailsLinkNamesItsJob(): void
    {
        $links = $this->xpath('Private/Templates/Job/List.html')->query('//td[@class="col-control"]//span[@class="visually-hidden"]');
        self::assertNotFalse($links);
        self::assertSame(1, $links->length);
        $hidden = $links->item(0);
        assert($hidden instanceof DOMElement);
        self::assertStringContainsString('show.title', $this->innerXml($hidden));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function longValues(): array
    {
        return [
            'source'         => ['Private/Templates/Job/Show.html', '//dd[contains(., "{job.sourceValue}")]'],
            'job error'      => ['Private/Templates/Job/Show.html', '//dd[contains(., "{job.errorMessage}")]'],
            'artifact cards' => ['Private/Templates/Job/Show.html', '//div[contains(@class, "card-body")][.//*[contains(., "{artifact.errorMessage}")]]'],
            'post text'      => ['Private/Templates/Job/Plan.html', '//td[contains(., "{post.script_text}")]'],
            'publish error'  => ['Private/Templates/Job/Plan.html', '//span[contains(., "{post.publish_error}")]'],
        ];
    }

    #[DataProvider('longValues')]
    public function testLongValuesBreakInsteadOfOverflowing(string $template, string $query): void
    {
        $nodes = $this->xpath($template)->query($query);
        self::assertNotFalse($nodes);
        self::assertGreaterThan(0, $nodes->length);
        foreach ($nodes as $node) {
            assert($node instanceof DOMElement);
            self::assertContains('text-break', preg_split('/\s+/', $node->getAttribute('class')) ?: []);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function componentBackgrounds(): array
    {
        return [
            'artifact cards' => ['Private/Templates/Job/Show.html', '//div[contains(@class, "card")]//*[contains(@class, "text-danger")]'],
            'review partial' => ['Private/Partials/Job/Review.html', '//*[contains(@class, "text-danger")]'],
            'plan table'     => ['Private/Templates/Job/Plan.html', '//table//*[contains(@class, "text-danger")]'],
        ];
    }

    /**
     * Core .text-danger measures 4.49:1 on a card and 4.18:1 on a striped table row in the
     * dark scheme (TYPO3 14.3.7, axe-core): error text there goes into the core error box.
     */
    #[DataProvider('componentBackgrounds')]
    public function testNoDangerColouredTextOnCardsOrTables(string $template, string $query): void
    {
        self::assertSame(0, $this->xpath($template)->query($query)?->length);
    }

    public function testEveryModuleClassIsDefinedInTheModuleStylesheet(): void
    {
        $css  = (string) file_get_contents(self::RESOURCES . 'Public/Css/backend.css');
        $used = [];
        foreach (self::backendTemplates() as [$template]) {
            preg_match_all('/\bnrrepurpose-[a-z-]+(?=[\s"])/', (string) file_get_contents(self::RESOURCES . $template), $matches);
            foreach ($matches[0] as $class) {
                if (preg_match('/class="[^"]*\b' . preg_quote($class, '/') . '\b/', (string) file_get_contents(self::RESOURCES . $template)) === 1) {
                    $used[$class] = true;
                }
            }
        }

        self::assertNotSame([], $used);
        foreach (array_keys($used) as $class) {
            self::assertStringContainsString('.' . $class . ' {', $css, $class);
        }
    }

    public function testTheModuleStylesheetUsesNoFixedColour(): void
    {
        $css = (string) file_get_contents(self::RESOURCES . 'Public/Css/backend.css');

        // Colours come from core custom properties only, so the dark scheme applies.
        self::assertSame(0, preg_match('/#[0-9a-f]{3,8}\b|\brgba?\(|\bhsla?\(/i', $css));
        self::assertSame(0, preg_match('/font-family:\s*+(?!inherit)/', $css));
    }

    private function xpath(string $template): DOMXPath
    {
        $html     = (string) file_get_contents(self::RESOURCES . $template);
        $dom      = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    /** @param list<string> $expected */
    private function classes(DOMElement $element, array $expected): string
    {
        $present = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];

        return implode(' ', array_values(array_intersect($expected, $present)));
    }

    private function innerXml(DOMElement $element): string
    {
        $xml = '';
        foreach ($element->childNodes as $child) {
            $xml .= $element->ownerDocument?->saveHTML($child);
        }

        return $xml;
    }
}
