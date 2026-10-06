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
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Property\Selector;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Sabberworm\CSS\Settings;
use SimpleXMLElement;
use SplFileInfo;
use TYPO3Fluid\Fluid\Core\Parser\TemplateProcessor\RemoveCommentsTemplateProcessor;

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
    private const string RESOURCES = __DIR__ . '/../../../Resources/';

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
            'pagination partial'    => ['Private/Partials/Job/Pagination.html'],
        ];
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
        $cells = $this->xpath('Private/Templates/Job/List.html')->query('//td[contains(., "{job.sourceValueForDisplay}")]');
        self::assertNotFalse($cells);
        self::assertSame(1, $cells->length);
        $cell = $cells->item(0);
        assert($cell instanceof DOMElement);

        // Core .col-responsive: one line, ellipsis, max 200px (TYPO3 13.4 and 14.3).
        self::assertStringContainsString('col-responsive', $cell->getAttribute('class'));
        // Hover shows the whole URL; the cell text itself stays complete for copying and screen readers.
        self::assertSame('{job.sourceValueForDisplay}', $cell->getAttribute('title'));
        self::assertSame('{job.sourceValueForDisplay}', trim($cell->textContent));
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
        // unnameable shadow-DOM progressbar: the module uses a native <progress>, which carries role, value,
        // range and name itself, with the percentage beside it for sight only.
        $bars = $xpath->query('//td[@class="col-progress"]/div[@class="nrrepurpose-progress"]/progress[@class="nrrepurpose-progress-track"]');
        self::assertSame(1, $bars?->length, 'progress column');
        $bar = $bars->item(0);
        assert($bar instanceof DOMElement);
        self::assertSame(['{job.progress}', '100'], [$bar->getAttribute('value'), $bar->getAttribute('max')]);
        self::assertFalse($bar->hasAttribute('role'), 'the native element has its own role');
        // One name per row, like the Details link: "Progress of job #4", not nine identical "Progress".
        self::assertStringContainsString('list.progress.label', $bar->getAttribute('aria-label'));
        self::assertStringContainsString('arguments: {0: job.uid}', $bar->getAttribute('aria-label'));
        self::assertSame(1, $xpath->query('//td[@class="col-progress"]//span[@class="nrrepurpose-progress-value"][@aria-hidden="true"][normalize-space(.)="{job.progress}%"]')?->length);
        self::assertSame(0, $xpath->query('//*[@role="progressbar"] | //typo3-backend-progress-bar')?->length);
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

    /**
     * As in core (cms-beuser SimplePagination, cms-backend ListNavigation): the jump form holds the
     * page-number field and nothing else, so the "Page … of N" label stays on one line around it.
     * With the label inside the inline form the pager cell broke over three lines.
     */
    public function testThePagerFormHoldsOnlyThePageNumberField(): void
    {
        $forms = $this->xpath('Private/Partials/Job/Pagination.html')->query('//form');
        self::assertNotFalse($forms);
        self::assertSame(1, $forms->length);
        $form = $forms->item(0);
        assert($form instanceof DOMElement);

        $children = [];
        foreach ($form->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child->tagName . '[name=' . $child->getAttribute('name') . ']';
            } elseif (trim($child->textContent) !== '') {
                $children[] = 'text: ' . trim($child->textContent);
            }
        }

        self::assertSame(['input[name=paginator-target-page]'], $children);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function longValues(): array
    {
        return [
            'source'         => ['Private/Templates/Job/Show.html', '//dd[contains(., "{job.sourceValueForDisplay}")]'],
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

    /**
     * The list above equals every `*.html` file under Private/Templates, Private/Partials and
     * Private/Layouts, subfolders included, except Templates/Generated (the image templates the
     * renderer turns into PNGs, not backend views). A backend template added anywhere there fails
     * this test until it is listed, and so gets every check that runs over the list.
     */
    public function testTheTemplateListCoversEveryBackendTemplate(): void
    {
        $found = [];
        foreach (['Private/Templates', 'Private/Partials', 'Private/Layouts'] as $directory) {
            if (!is_dir(self::RESOURCES . $directory)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::RESOURCES . $directory, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                assert($file instanceof SplFileInfo);
                $path = substr($file->getPathname(), strlen(self::RESOURCES));
                if ($file->getExtension() === 'html' && !str_starts_with($path, 'Private/Templates/Generated/')) {
                    $found[] = $path;
                }
            }
        }

        $listed = array_column(self::backendTemplates(), 0);
        sort($found);
        sort($listed);
        self::assertSame($found, $listed);
    }

    /**
     * Inline scripts ask for the CSP nonce with `csp="true"`. `useNonce` renders the same nonce
     * but is deprecated since TYPO3 14.2, and the rendered page cannot tell the two apart, so the
     * template source is the one place the difference shows. The argument name is refused in any
     * form (tag or inline syntax, any namespace alias) once the Fluid comments, which may name
     * it, are removed the way Fluid removes them; each `<f:asset.script>` tag must also carry
     * `csp="true"`.
     */
    #[DataProvider('backendTemplates')]
    public function testInlineScriptsUseTheCspArgument(string $template): void
    {
        $code = $this->fluidCode($template);
        self::assertStringNotContainsString('useNonce', $code, $template);

        preg_match_all('/<f:asset\.script\b[^>]*>/', $code, $tags);
        foreach ($tags[0] as $tag) {
            self::assertMatchesRegularExpression('/\bcsp="true"/', $tag, $template);
        }
    }

    public function testTheJobDetailReloadScriptIsChecked(): void
    {
        // The job detail view's reload script; if this pattern matched nothing, the per-tag check would check nothing.
        self::assertSame(1, preg_match_all('/<f:asset\.script\b[^>]*\bcsp="true"/', (string) file_get_contents(self::RESOURCES . 'Private/Templates/Job/Show.html')));
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
            'progress track is drawn'             => ['.nrrepurpose-progress-track', ['appearance' => 'none', 'flex' => '1 1 auto', 'min-width' => '3rem', 'width' => '3rem', 'height' => '.5rem', 'background-color' => 'var(--typo3-surface-container-high)']],
            'progress track in WebKit/Blink'      => ['.nrrepurpose-progress-track::-webkit-progress-bar', ['background-color' => 'var(--typo3-surface-container-high)']],
            'progress fill in WebKit/Blink'       => ['.nrrepurpose-progress-track::-webkit-progress-value', ['background-color' => 'var(--typo3-component-primary-color)']],
            'progress fill in Gecko'              => ['.nrrepurpose-progress-track::-moz-progress-bar', ['background-color' => 'var(--typo3-component-primary-color)']],
        ];
    }

    /**
     * The last declaration of each property across every rule whose selector list contains the
     * class, in source order: the property's own value. Whether another property overrides it
     * (`all: revert`, `min-inline-size`, `text-wrap-mode`) is not judged here; the complete
     * declaration set is pinned in testTheStylesheetDeclaresExactlyThePinnedSet(), so no such
     * declaration can be added without that test failing.
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

    /**
     * The complete set of declarations in backend.css, rule by rule, in source order. Any
     * declaration added, removed, reordered or changed fails here with the difference, whatever
     * property it uses, so a property that overrides a checked one (`all: revert`,
     * `min-inline-size` against `min-width`, `text-wrap-mode` against `white-space`) cannot slip
     * in beside the targeted tests. Its limit: a change made on purpose must update this list in
     * the same commit, and the list says nothing about whether the new value is right; that is
     * what the targeted tests above and the review are for. Rules from the core backend CSS are
     * outside this file and outside this test.
     */
    public function testTheStylesheetDeclaresExactlyThePinnedSet(): void
    {
        $pinned = [
            ['.nrrepurpose-progress', 'display', 'flex'],
            ['.nrrepurpose-progress', 'align-items', 'center'],
            ['.nrrepurpose-progress', 'gap', 'calc(var(--typo3-spacing, 1rem) / 2)'],
            ['.nrrepurpose-progress-track', '-webkit-appearance', 'none'],
            ['.nrrepurpose-progress-track', 'appearance', 'none'],
            ['.nrrepurpose-progress-track', 'display', 'block'],
            ['.nrrepurpose-progress-track', 'flex', '1 1 auto'],
            ['.nrrepurpose-progress-track', 'min-width', '3rem'],
            ['.nrrepurpose-progress-track', 'height', '.5rem'],
            ['.nrrepurpose-progress-track', 'width', '3rem'],
            ['.nrrepurpose-progress-track', 'overflow', 'hidden'],
            ['.nrrepurpose-progress-track', 'border', '0'],
            ['.nrrepurpose-progress-track', 'border-radius', '.25rem'],
            ['.nrrepurpose-progress-track', 'background-color', 'var(--typo3-surface-container-high)'],
            ['.nrrepurpose-progress-track', 'color', 'var(--typo3-component-primary-color)'],
            ['.nrrepurpose-progress-track::-webkit-progress-bar', 'background-color', 'var(--typo3-surface-container-high)'],
            ['.nrrepurpose-progress-track::-webkit-progress-value', 'background-color', 'var(--typo3-component-primary-color)'],
            ['.nrrepurpose-progress-track::-moz-progress-bar', 'background-color', 'var(--typo3-component-primary-color)'],
            ['.nrrepurpose-progress-value', 'flex', '0 0 auto'],
            ['.nrrepurpose-progress-value', 'min-width', '3.5ch'],
            ['.nrrepurpose-progress-value', 'text-align', 'end'],
            ['.nrrepurpose-progress-value', 'font-variant-numeric', 'tabular-nums'],
            ['.nrrepurpose-audio', 'display', 'block'],
            ['.nrrepurpose-audio', 'width', '100%'],
            ['.nrrepurpose-audio', 'max-width', '640px'],
            ['.nrrepurpose-preview', 'display', 'block'],
            ['.nrrepurpose-preview', 'max-width', '100%'],
            ['.nrrepurpose-preview', 'max-height', '480px'],
            ['.nrrepurpose-preview', 'height', 'auto'],
            ['.nrrepurpose-preview-framed', 'border', 'var(--typo3-component-border-width, 1px) solid var(--typo3-component-border-color, var(--bs-border-color))'],
            ['.nrrepurpose-story-strip', 'display', 'flex'],
            ['.nrrepurpose-story-strip', 'flex-direction', 'row'],
            ['.nrrepurpose-story-strip', 'gap', 'var(--typo3-spacing, 1rem)'],
            ['.nrrepurpose-story-strip', 'overflow-x', 'auto'],
            ['.nrrepurpose-story-strip', 'padding-bottom', 'calc(var(--typo3-spacing, 1rem) / 2)'],
            ['.nrrepurpose-story-slide', 'flex', '0 0 auto'],
            ['.nrrepurpose-story-slide', 'text-align', 'center'],
            ['.nrrepurpose-story-slide-image', 'display', 'block'],
            ['.nrrepurpose-story-slide-image', 'height', '320px'],
            ['.nrrepurpose-story-slide-image', 'width', 'auto'],
            ['.nrrepurpose-story-slide-image', 'border', 'var(--typo3-component-border-width, 1px) solid var(--typo3-component-border-color, var(--bs-border-color))'],
            ['.nrrepurpose-story-slide-placeholder', 'box-sizing', 'border-box'],
            ['.nrrepurpose-story-slide-placeholder', 'display', 'flex'],
            ['.nrrepurpose-story-slide-placeholder', 'align-items', 'center'],
            ['.nrrepurpose-story-slide-placeholder', 'justify-content', 'center'],
            ['.nrrepurpose-story-slide-placeholder', 'height', '320px'],
            ['.nrrepurpose-story-slide-placeholder', 'width', '180px'],
            ['.nrrepurpose-story-slide-placeholder', 'padding', 'calc(var(--typo3-spacing, 1rem) / 2)'],
            ['.nrrepurpose-story-slide-placeholder', 'border', 'var(--typo3-component-border-width, 1px) dashed var(--typo3-component-border-color, var(--bs-border-color))'],
            ['.nrrepurpose-story-slide-meta', 'max-width', '200px'],
            ['.nrrepurpose-story-slide-meta', 'text-align', 'start'],
            ['.nrrepurpose-pre', 'white-space', 'pre-wrap'],
            ['.nrrepurpose-pre', 'overflow-wrap', 'anywhere'],
            ['.nrrepurpose-pre-text', 'font-family', 'inherit'],
            ['.nrrepurpose-pre-text', 'font-size', 'inherit'],
        ];

        $expected = array_map(fn (array $d): string => $d[0] . ' { ' . $d[1] . ': ' . $this->normalised($d[2]) . ' }', $pinned);
        $actual   = array_map(static fn (array $d): string => implode(', ', $d[0]) . ' { ' . $d[1] . ': ' . $d[2] . ' }', $this->declarations());

        self::assertSame($expected, $actual);
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
     * three assertions make source order equal the cascade among these rules for the same
     * property: only plain top-level rules, no !important, and every selector is one plain module
     * class written as `.nrrepurpose-name`, or the progress track with one of its three per-engine
     * pseudo-elements. Overrides by other properties are closed by the pinned declaration set, not here.
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
                // The one addition: the progress track's per-engine pseudo-elements, which style the parts
                // of the native <progress> and cannot compete with a rule for the element itself.
                self::assertMatchesRegularExpression('/^\.nrrepurpose-[a-z]+(?:-[a-z]+)*$|^\.nrrepurpose-progress-track::(?:-webkit-progress-bar|-webkit-progress-value|-moz-progress-bar)$/', $selector, 'backend.css: every selector is one plain module class');
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

    /** The template source as Fluid parses it: comments removed by Fluid's own processor. */
    private function fluidCode(string $template): string
    {
        return (new RemoveCommentsTemplateProcessor())->preProcessSource((string) file_get_contents(self::RESOURCES . $template));
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
