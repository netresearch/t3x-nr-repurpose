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
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Property\Selector;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Sabberworm\CSS\Settings;
use SimpleXMLElement;

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
    public function testNoInlineStyles(string $template): void
    {
        $styled = [];
        foreach ($this->xpath($template)->query('//*[@style]') ?: [] as $element) {
            assert($element instanceof DOMElement);
            // The progress fill width is the job's value, not layout: the one inline style kept.
            if ($element->getAttribute('class') === 'nrrepurpose-progress-fill' && $element->getAttribute('style') === 'width: {job.progress}%;') {
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

        // Core v14 has no .progress/.progress-bar CSS, and <typo3-backend-progress-bar> is @internal with an
        // unnameable shadow-DOM progressbar: the module draws its own, named, with the value visible.
        $bars = $xpath->query('//td[@class="col-progress"]/div[@class="nrrepurpose-progress"]');
        self::assertSame(1, $bars?->length, 'progress column');
        $bar = $bars->item(0);
        assert($bar instanceof DOMElement);
        self::assertSame(
            ['progressbar', '{job.progress}', '0', '100'],
            [$bar->getAttribute('role'), $bar->getAttribute('aria-valuenow'), $bar->getAttribute('aria-valuemin'), $bar->getAttribute('aria-valuemax')],
        );
        // One name per row, like the Details link: "Progress of job 4", not nine identical "Progress".
        self::assertStringContainsString('list.progress.label', $bar->getAttribute('aria-label'));
        self::assertStringContainsString('arguments: {0: job.uid}', $bar->getAttribute('aria-label'));
        self::assertSame(1, $xpath->query('.//span[@class="nrrepurpose-progress-value"][normalize-space(.)="{job.progress}%"]', $bar)?->length);
        self::assertSame(0, $xpath->query('//typo3-backend-progress-bar')?->length);
        // Matched on the source: how an HTML parser nests an unknown, self-closed <f:render/>
        // differs between libxml builds (CI's differs from the runTests.sh image).
        self::assertMatchesRegularExpression(
            '#<td class="col-nowrap">\s*<f:render partial="Job/ArtifactSummaries" #',
            (string) file_get_contents(self::RESOURCES . 'Private/Templates/Job/List.html'),
            'artifact icons stay on one line',
        );
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
            // Everything from the first card on is card content (the back link after it has no colour).
            'artifact cards' => ['Private/Templates/Job/Show.html', '<div class="card'],
            // The review partial is only rendered inside an artifact card.
            'review partial' => ['Private/Partials/Job/Review.html', '<f:if'],
            'plan table'     => ['Private/Templates/Job/Plan.html', '<table'],
        ];
    }

    /**
     * Core .text-danger measures 4.49:1 on a card and 4.18:1 on a striped table row in the
     * dark scheme (TYPO3 14.3.7, axe-core): error text there goes into the core error box.
     * Checked on the source from the component on: the nesting an HTML parser builds for the
     * Fluid tags differs between libxml builds.
     */
    #[DataProvider('componentBackgrounds')]
    public function testNoDangerColouredTextOnCardsOrTables(string $template, string $componentStart): void
    {
        $source = (string) file_get_contents(self::RESOURCES . $template);
        $offset = strpos($source, $componentStart);
        self::assertIsInt($offset, $componentStart);

        self::assertStringNotContainsString('text-danger', substr($source, $offset));
    }

    public function testEveryModuleClassIsDefinedInTheModuleStylesheet(): void
    {
        $used = [];
        foreach (self::backendTemplates() as [$template]) {
            $source = (string) file_get_contents(self::RESOURCES . $template);
            preg_match_all('/\bnrrepurpose-[a-z-]+(?=[\s"])/', $source, $matches);
            foreach ($matches[0] as $class) {
                if (preg_match('/class="[^"]*\b' . preg_quote($class, '/') . '\b/', $source) === 1) {
                    $used[$class] = true;
                }
            }
        }

        self::assertNotSame([], $used);
        foreach (array_keys($used) as $class) {
            self::assertNotSame([], $this->declarationsFor('.' . $class), $class . ' has no rule in backend.css');
        }
    }

    /** @return array<string, array{0: string, 1: array<string, string>}> */
    public static function keyDeclarations(): array
    {
        return [
            'prompt and generated text wraps'     => ['.nrrepurpose-pre', ['white-space' => 'pre-wrap', 'overflow-wrap' => 'anywhere']],
            'copy-ready text keeps the body font' => ['.nrrepurpose-pre-text', ['font-family' => 'inherit']],
            'previews never wider than the card'  => ['.nrrepurpose-preview', ['max-width' => '100%', 'max-height' => '480px', 'height' => 'auto']],
            'audio player fits the card'          => ['.nrrepurpose-audio', ['width' => '100%', 'max-width' => '640px']],
            'story strip scrolls, not the page'   => ['.nrrepurpose-story-strip', ['display' => 'flex', 'overflow-x' => 'auto']],
            'slides keep their size in the strip' => ['.nrrepurpose-story-slide', ['flex' => '0 0 auto']],
            'progress track is drawn'             => ['.nrrepurpose-progress-track', ['flex' => '1 1 auto', 'min-width' => '3rem', 'height' => '.5rem', 'background-color' => 'var(--typo3-surface-container-high)']],
            'progress fill colour'                => ['.nrrepurpose-progress-fill', ['height' => '100%', 'background-color' => 'var(--typo3-component-primary-color)']],
        ];
    }

    /**
     * The last declaration of each property across every rule whose selector list contains the
     * class, in source order. declarations() makes that the value the cascade applies: every
     * rule is a plain top-level rule (no at-rule, no nesting), no declaration is !important, and
     * every selector in the file is one plain module class written as `.nrrepurpose-name` (no
     * attribute selector, escape, type prefix or combinator), so all have one specificity.
     *
     * @param array<string, string> $expected
     */
    #[DataProvider('keyDeclarations')]
    public function testTheModuleStylesheetKeepsItsKeyDeclarations(string $class, array $expected): void
    {
        $effective = [];
        foreach ($this->declarationsFor($class) as [$property, $value]) {
            $effective[$property] = $value;
        }

        foreach ($expected as $property => $value) {
            self::assertArrayHasKey($property, $effective, $class . ' ' . $property);
            self::assertSame($this->normalised($value), $effective[$property], $class . ' ' . $property);
        }
    }

    public function testTheProgressBarItselfHasNoMinimumWidth(): void
    {
        // min-width: 8rem on the bar widened the job list to 661 px in a 657 px container at 1280 px
        // and cut off the actions column; the minimum belongs to the track (3rem).
        self::assertSame([], $this->valuesOf('.nrrepurpose-progress', 'min-width'));
    }

    public function testTheProgressTrackDeclaresItsMinimumWidthOnce(): void
    {
        // A second min-width, in the same rule or in another rule for the track anywhere later in
        // the file, wins over the 3rem and brings the 1280 px overflow back.
        self::assertSame([$this->normalised('3rem')], $this->valuesOf('.nrrepurpose-progress-track', 'min-width'));
    }

    public function testTheModuleStylesheetUsesNoFixedColour(): void
    {
        $declarations = $this->declarations();
        self::assertNotSame([], $declarations);

        foreach ($declarations as [$selectors, $property, $value]) {
            // Colours come from core custom properties only, so the dark scheme applies.
            self::assertSame(0, preg_match('/#[0-9a-f]{3,8}\b|\brgba?\(|\bhsla?\(/i', $value), implode(',', $selectors) . ' ' . $property . ': ' . $value);
            if ($property === 'font-family') {
                self::assertSame('inherit', $value, implode(',', $selectors));
            }
        }
    }

    /**
     * Every declaration of backend.css, parsed strictly, in source order. Strict mode rejects what
     * the lenient parser would flatten or skip (nested rules, an unclosed rule, a stray `}`), and
     * three assertions make source order equal the cascade for the module classes: only plain
     * top-level rules, no !important, and every selector is one plain module class written as
     * `.nrrepurpose-name`.
     *
     * @return list<array{0: list<string>, 1: string, 2: string}> selectors, property, value
     */
    private function declarations(): array
    {
        $document = (new Parser((string) file_get_contents(self::RESOURCES . 'Public/Css/backend.css'), Settings::create()->beStrict()))->parse();
        $format   = OutputFormat::createCompact();
        $all      = [];
        foreach ($document->getContents() as $item) {
            self::assertInstanceOf(DeclarationBlock::class, $item, 'backend.css: only plain rules, no at-rules');
            $selectors = array_map(static fn (Selector $selector): string => $selector->render($format), $item->getSelectors());
            foreach ($selectors as $selector) {
                // The stylesheet styles the module's own classes only. Anything else could reach a
                // module element with a different specificity or under a spelling this reader does
                // not match: an attribute selector ([class~="nrrepurpose-pre"]), an escaped name
                // (.nrrepurpose\-pre, .nrrepurpose\2d pre), a type prefix, a combinator.
                self::assertMatchesRegularExpression('/^\.nrrepurpose-[a-z]+(?:-[a-z]+)*$/', $selector, 'backend.css: every selector is one plain module class');
            }

            foreach ($item->getDeclarations() as $declaration) {
                self::assertFalse($declaration->getIsImportant(), 'backend.css: ' . implode(',', $selectors) . ' ' . $declaration->getPropertyName() . ' is !important');
                $value = $declaration->getValue();
                $all[] = [$selectors, $declaration->getPropertyName(), is_string($value) ? $value : $value->render($format)];
            }
        }

        return $all;
    }

    /** @return list<array{0: string, 1: string}> property, value of every rule whose selector list contains the class */
    private function declarationsFor(string $class): array
    {
        return array_values(array_map(
            static fn (array $declaration): array => [$declaration[1], $declaration[2]],
            array_filter($this->declarations(), static fn (array $declaration): bool => in_array($class, $declaration[0], true)),
        ));
    }

    /** @return list<string> */
    private function valuesOf(string $class, string $property): array
    {
        return array_values(array_map(
            static fn (array $declaration): string => $declaration[1],
            array_filter($this->declarationsFor($class), static fn (array $declaration): bool => $declaration[0] === $property),
        ));
    }

    /** A value as the parser renders it, so the expectations can be written as in the stylesheet. */
    private function normalised(string $value): string
    {
        $declarations = (new Parser('a{x:' . $value . '}', Settings::create()->beStrict()))->parse()->getAllDeclarationBlocks()[0]->getDeclarations();
        $parsed       = $declarations[0]->getValue();

        return is_string($parsed) ? $parsed : $parsed->render(OutputFormat::createCompact());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function trackedTranslations(): array
    {
        $cases = [];
        foreach (glob(self::RESOURCES . 'Private/Language/*.*.xlf') ?: [] as $file) {
            // de.locallang.xlf translates locallang.xlf: the English file is what the target must match.
            $english                = dirname($file) . '/' . preg_replace('/^[a-z]{2}(?:_[A-Z]{2})?\./', '', basename($file));
            $cases[basename($file)] = [$file, $english];
        }

        return $cases;
    }

    /**
     * A target that drops a %d or %s of the English label renders without the value (the job
     * number of a label). The reference is the English file, matched by id: the <source> copied
     * into a translation file is never rendered and can be as stale as its target.
     */
    #[DataProvider('trackedTranslations')]
    public function testEveryTranslationKeepsThePlaceholdersOfTheEnglishLabel(string $file, string $english): void
    {
        self::assertFileExists($english);
        $reference = [];
        foreach ($this->transUnits($english) as $unit) {
            $reference[(string) $unit['id']] = (string) $unit->source;
        }

        $compared = 0;
        foreach ($this->transUnits($file) as $unit) {
            if (!isset($unit->target)) {
                continue;
            }

            $id = (string) $unit['id'];
            self::assertArrayHasKey($id, $reference, basename($file) . ' ' . $id . ' has no English label');
            self::assertSame($this->placeholders($reference[$id]), $this->placeholders((string) $unit->target), basename($file) . ' ' . $id);
            // A label with placeholders goes through sprintf, where a `%` that starts no placeholder
            // (`50 % von %s`) is a ValueError. Labels without placeholders are rendered as they are.
            if ($this->placeholders($reference[$id]) !== []) {
                self::assertFalse($this->hasLonePercent((string) $unit->target), basename($file) . ' ' . $id . ': lone %');
                self::assertFalse($this->hasLonePercent($reference[$id]), basename($file) . ' ' . $id . ': lone % in the English label');
            }

            ++$compared;
        }

        self::assertGreaterThan(0, $compared, basename($file));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function placeholderShapes(): array
    {
        return [
            'plain in order'      => ['%s of %d', ['1:s', '2:d']],
            'positional reorder'  => ['%2$d of %1$s', ['1:s', '2:d']],
            'escaped percent'     => ['%s of 100%%', ['1:s']],
            'escaped then letter' => ['%s of %%s', ['1:s']],
        ];
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function percentSigns(): array
    {
        return [
            'placeholders only'        => ['%s of %d', false],
            'escaped percent'          => ['%s of 100%%', false],
            'positional'               => ['%2$d of %1$s', false],
            'lone percent before text' => ['50 % von %s', true],
            'lone percent at the end'  => ['%s of 100%', true],
        ];
    }

    #[DataProvider('percentSigns')]
    public function testTheLonePercentReading(string $text, bool $lone): void
    {
        self::assertSame($lone, $this->hasLonePercent($text));
    }

    /** A `%` that is neither `%%` nor the start of a %d / %s / %N$d / %N$s placeholder. */
    private function hasLonePercent(string $text): bool
    {
        return preg_match('/%(?!(?:\d+\$)?[ds])/', str_replace('%%', '', $text)) === 1;
    }

    /** @param list<string> $expected */
    #[DataProvider('placeholderShapes')]
    public function testThePlaceholderReading(string $text, array $expected): void
    {
        self::assertSame($expected, $this->placeholders($text));
    }

    /**
     * The sprintf arguments a label consumes, as sorted "position:type": `%%` is a literal percent
     * sign, unnumbered placeholders take positions in order, `%2$s` names its position, so a
     * translation may reorder with positional placeholders.
     *
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/%(?:(\d+)\$)?([ds])|%%/', $text, $matches, PREG_SET_ORDER);
        $next = 1;
        $used = [];
        foreach ($matches as $match) {
            if ($match[0] === '%%') {
                continue;
            }

            $position = $match[1] !== '' ? (int) $match[1] : $next++;
            $used[]   = $position . ':' . $match[2];
        }

        sort($used);

        return array_values(array_unique($used));
    }

    /** @return list<SimpleXMLElement> */
    private function transUnits(string $file): array
    {
        $xliff = simplexml_load_file($file);
        self::assertNotFalse($xliff, $file);
        $units = $xliff->xpath('//*[local-name()="trans-unit"]') ?: [];
        self::assertNotSame([], $units, $file);

        return array_values($units);
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
