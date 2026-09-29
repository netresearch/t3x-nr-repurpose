<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * The Netresearch-theme output templates carry the brand placement of netresearch-branding
 * (references/logo.md, "Placement Requirements"): the symbol-only [n] logo in a header, and a
 * footer with "Netresearch DTT GmbH" linked to https://www.netresearch.de/.
 *
 * The logo is inline SVG, never a file or URL reference: the renderer turns these pages into
 * PNG and PDF and may run with outside requests blocked.
 */
final class BrandingRenderingTest extends AbstractFunctionalTestCase
{
    private const string LOGO_TITLE = '<title>Netresearch DTT GmbH</title>';

    private const string FRAME = '<path fill="#2F99A4" d="M209.6,0V31.62h32.77a26.38,26.38,0,0,1,26.44,26.43V242';

    private const string LETTER = '<path fill="#585961" d="M221.44,120.41c0-34.48-13.94-57.82-48.93-57.82';

    private const string LOGO_IN_HEADER = '~<header\b[^>]*>(?:(?!</header>).)*?' . self::LOGO_TITLE . '~s';

    private const string FOOTER_LINK = '~<footer\b[^>]*>(?:(?!</footer>).)*?<a href="https://www\.netresearch\.de/"[^>]*>Netresearch DTT GmbH</a>~s';

    /**
     * Meta's Stories ads guide asks to keep 14% of a 9:16 story (269 of 1920 px) at the top
     * free of text and logos, where the progress bar and the profile sit; the templates take
     * the same 14% at the bottom for the reply bar (Meta's 35% there is for an ad's
     * call-to-action). The story video zooms every slide in by 8% around its centre
     * (FfmpegSlideshowRenderer), which moves an element d px from an edge to
     * 960 - (960 - d) * 1.08 px from it on the last frame. So the templates keep
     * d >= (269 + 960 * 0.08) / 1.08, rounded up: 321 px.
     */
    private const int STORY_SAFE_EDGE = 321;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: int, 3: int}> template, variables, logos, footer links */
    public static function nrTemplates(): array
    {
        $schaubild = ['title' => 'Title', 'bodyHtml' => '<p>Body</p>', 'language' => 'de', 'aiLabel' => 'KI-generiert'];
        $story     = ['headline' => 'Headline', 'subline' => 'Subline', 'slideIndex' => 2, 'slideTotal' => 3, 'sourceLabel' => 'https://example.com/', 'aiLabel' => 'AI-generated'];
        $deck      = [
            'language'    => 'de',
            'sourceLabel' => 'https://example.com/',
            'content'     => [
                'title'       => 'Deck',
                'subtitle'    => 'Sub',
                'slides'      => [['heading' => 'One', 'bullets' => ['a']], ['heading' => 'Two', 'bullets' => ['b']]],
                'takeaway'    => 'Takeaway',
                'closingLine' => 'Closing',
            ],
        ];
        $handout = [
            'language'    => 'de',
            'sourceLabel' => 'https://example.com/',
            'labels'      => ['keyFacts' => 'Key facts'],
            'content'     => ['title' => 'Handout', 'lead' => 'Lead', 'sections' => [['heading' => 'H', 'paragraphs' => ['P']]], 'keyFacts' => ['F']],
        ];

        return [
            'Schaubild, opaque'                => ['Schaubild/Nr', $schaubild + ['transparent' => false], 1, 1],
            'Schaubild, transparent overlay'   => ['Schaubild/Nr', $schaubild + ['transparent' => true], 1, 1],
            'Story cover'                      => ['Story/Nr', $story + ['role' => 'cover', 'transparent' => false], 1, 1],
            'Story point'                      => ['Story/Nr', $story + ['role' => 'point', 'transparent' => false], 1, 1],
            'Story outro, transparent overlay' => ['Story/Nr', $story + ['role' => 'outro', 'transparent' => true], 1, 1],
            // One logo per slide, since every slide is its own PDF page; the company link on
            // the title and the closing slide.
            'Slide deck' => ['SlideDeck/Nr', $deck, 4, 2],
            'Handout'    => ['Handout/Nr', $handout, 1, 1],
        ];
    }

    /** @param array<string, mixed> $variables */
    #[DataProvider('nrTemplates')]
    public function testTheSymbolLogoSitsInTheHeaderAsInlineSvgInTheBrandColours(string $template, array $variables, int $logos, int $footers): void
    {
        $html = $this->render($template, $variables);

        self::assertSame($logos, substr_count($html, self::LOGO_TITLE), $html);
        self::assertSame($logos, preg_match_all(self::LOGO_IN_HEADER, $html), 'every logo inside a <header>');
        self::assertSame($logos, substr_count($html, self::FRAME));
        self::assertSame($logos, substr_count($html, self::LETTER));
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('netresearch-symbol-only.svg', $html);
    }

    /** @param array<string, mixed> $variables */
    #[DataProvider('nrTemplates')]
    public function testTheFooterLinksTheCompanyName(string $template, array $variables, int $logos, int $footers): void
    {
        self::assertSame($footers, preg_match_all(self::FOOTER_LINK, $this->render($template, $variables)));
    }

    /** @return array<string, array{0: string}> */
    public static function storyTemplates(): array
    {
        return ['Netresearch theme' => ['Story/Nr'], 'Neutral theme' => ['Story/Neutral']];
    }

    /**
     * The story stacks its copy from the bottom edge, so the bottom padding decides whether
     * the last line lands under the reply bar; the logo, the AI label and the slide indicator
     * are placed from the top.
     */
    #[DataProvider('storyTemplates')]
    public function testTheStoryKeepsItsElementsOutOfTheStoryUiBandsAlsoInTheVideo(string $template): void
    {
        $html = $this->render($template, ['headline' => 'H', 'subline' => 'S', 'role' => 'point', 'slideIndex' => 2, 'slideTotal' => 3, 'sourceLabel' => 'https://example.com/', 'transparent' => false, 'aiLabel' => 'AI-generated']);

        // padding: top horizontal bottom
        self::assertSame(1, preg_match('~\.story\s*\{[^}]*\bpadding:\s*\d+px\s+\d+px\s+(\d+)px\s*;~', $html, $padding), 'three-value padding on .story');
        self::assertGreaterThanOrEqual(self::STORY_SAFE_EDGE, (int) $padding[1], 'bottom padding of .story');

        self::assertSame(1, preg_match('~\.story__meta\s*\{[^}]*\btop:\s*(\d+)px~', $html, $top), 'top on .story__meta');
        self::assertGreaterThanOrEqual(self::STORY_SAFE_EDGE, (int) $top[1], 'top of .story__meta');
        // Label and indicator sit in that row; neither is placed on its own.
        self::assertSame(1, preg_match('~<div class="story__meta">\s*<div class="ai-label">AI-generated</div>\s*<div class="story__indicator">2/3</div>\s*</div>~', $html), $html);
        self::assertSame(0, preg_match('~\.(?:ai-label|story__indicator)\s*\{[^}]*\b(?:top|position):~', $html), 'no own position for the label or the indicator');

        if ($template === 'Story/Nr') {
            self::assertSame(1, preg_match('~\.story__header\s*\{[^}]*\btop:\s*(\d+)px~', $html, $header), 'top on .story__header');
            self::assertGreaterThanOrEqual(self::STORY_SAFE_EDGE, (int) $header[1], 'top of .story__header');
        }
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> template, variables, footer selector */
    public static function transparentFooters(): array
    {
        return [
            'Schaubild' => ['Schaubild/Nr', ['title' => 'T', 'bodyHtml' => '<p>B</p>', 'language' => 'de', 'aiLabel' => null], 'schaubild__footer'],
            'Story'     => ['Story/Nr', ['headline' => 'H', 'subline' => 'S', 'role' => 'outro', 'slideIndex' => 3, 'slideTotal' => 3, 'sourceLabel' => 'https://example.com/', 'aiLabel' => null], 'story__brand'],
        ];
    }

    /**
     * The transparent render is composited over a KI background, where the footer text alone
     * is illegible over light parts; it gets a white plate there, the opaque render does not.
     *
     * @param array<string, mixed> $variables
     */
    #[DataProvider('transparentFooters')]
    public function testTheFooterHasAWhitePlateOnlyOverAKiBackground(string $template, array $variables, string $footer): void
    {
        $plate = '~\.' . $footer . '\s*\{[^}]*\bbackground:\s*#ffffff;~';

        self::assertSame(1, preg_match($plate, $this->render($template, $variables + ['transparent' => true])), 'plate in the transparent render');
        self::assertSame(0, preg_match($plate, $this->render($template, $variables + ['transparent' => false])), 'no plate in the opaque render');
    }

    /**
     * The white story copy is composited over a KI background, and the Neutral theme asks the
     * image model for a light one: without a backing of its own it is white on white. In the
     * transparent render the headline, the subline, the source and the slide indicator sit on
     * a dark plate, line by line; the opaque render keeps its gradient and needs none.
     */
    #[DataProvider('storyTemplates')]
    public function testTheStoryCopyHasADarkPlateOnlyOverAKiBackground(string $template): void
    {
        $variables = ['headline' => 'H', 'subline' => 'S', 'role' => 'outro', 'slideIndex' => 3, 'slideTotal' => 3, 'sourceLabel' => 'https://example.com/', 'aiLabel' => null];
        $plate     = '~\.story__plate\s*\{[^}]*\bbackground:\s*#[0-9a-f]{6};[^}]*\bbox-decoration-break:\s*clone;~';
        $indicator = '~\.story__indicator\s*\{[^}]*\bbackground:\s*#[0-9a-f]{6};~';

        $transparent = $this->render($template, $variables + ['transparent' => true]);
        self::assertSame(1, preg_match($plate, $transparent), 'copy plate in the transparent render');
        self::assertSame(1, preg_match($indicator, $transparent), 'indicator plate in the transparent render');
        foreach (['<h1 class="story__headline"><span class="story__plate">H</span></h1>', '<p class="story__subline"><span class="story__plate">S</span></p>', '<p class="story__source"><span class="story__plate">https://example.com/</span></p>'] as $element) {
            self::assertStringContainsString($element, $transparent);
        }

        $opaque = $this->render($template, $variables + ['transparent' => false]);
        self::assertSame(0, preg_match($plate, $opaque), 'no copy plate in the opaque render');
        self::assertSame(0, preg_match($indicator, $opaque), 'no indicator plate in the opaque render');
    }

    /**
     * A long German compound can be wider than the 888px line at headline size and ran off the
     * right edge of the cover. It breaks inside the word instead.
     */
    #[DataProvider('storyTemplates')]
    public function testALongWordInTheHeadlineBreaksInsteadOfLeavingTheSlide(string $template): void
    {
        $html = $this->render($template, ['headline' => 'H', 'subline' => 'S', 'role' => 'cover', 'slideIndex' => 1, 'slideTotal' => 3, 'sourceLabel' => '', 'transparent' => false, 'aiLabel' => null]);

        self::assertSame(1, preg_match('~\.story__headline\s*\{[^}]*\boverflow-wrap:\s*break-word;~', $html));
    }

    /**
     * The copy stacks up from 330px above the bottom and has to stop under the logo, which ends
     * 450px from the top: 1140px for accent, headline, subline, source and company line. A
     * 60-character headline of words that do not pair on a line took eight lines at the cover's
     * former 116px (1020px) and pushed the accent 286px up into the logo, rendered; at 88px the
     * worst rendered cover keeps 138px to the logo, the worst outro 6px. The source label (a URL
     * or file name of any length) is held to two lines for the same reason, and the transparent
     * render's plate widens the line by a shadow, not by padding, so the copy wraps as in the
     * opaque render.
     */
    #[DataProvider('storyTemplates')]
    public function testTheStoryCopyCannotGrowIntoTheLogo(string $template): void
    {
        $variables = ['headline' => 'H', 'subline' => 'S', 'slideIndex' => 1, 'slideTotal' => 3, 'sourceLabel' => 'https://example.com/', 'aiLabel' => null];

        foreach (['cover', 'point', 'outro'] as $role) {
            $html = $this->render($template, $variables + ['role' => $role, 'transparent' => true]);
            self::assertSame(1, preg_match('~\.story__headline\s*\{[^}]*\bfont-size:\s*(\d+)px~', $html, $size), $role);
            self::assertLessThanOrEqual(88, (int) $size[1], $role . ' headline size');
            self::assertSame(1, preg_match('~\.story__plate\s*\{[^}]*\bpadding:\s*\d+px 0;~', $html), $role . ': plate without side padding');
        }

        self::assertSame(1, preg_match('~\.story__source\s*\{[^}]*-webkit-line-clamp:\s*2;[^}]*overflow:\s*hidden;~', $this->render($template, $variables + ['role' => 'outro', 'transparent' => false])), 'source held to two lines');
    }

    /** @param array<string, mixed> $variables */
    private function render(string $template, array $variables): string
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templatePathAndFilename: GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Resources/Private/Templates/Generated/' . $template . '.html'),
        ));
        $view->assignMultiple($variables);

        return $view->render();
    }
}
