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

    /** 14% of the 1920px story height, rounded up. */
    private const int STORY_UI_BAND = 269;

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

    /**
     * Meta's Stories spec asks to keep 14% of a 9:16 story (269 of 1920 px) at the top free
     * of text and logos, where the progress bar and the profile sit. The template takes the
     * same 14% at the bottom for the reply bar (Meta's 35% there is for an ad's
     * call-to-action).
     * The story stacks its copy from the bottom edge, so the bottom padding decides whether
     * the company line lands under the reply bar.
     */
    public function testTheStoryKeepsLogoAndCompanyLineOutOfTheInstagramUiBands(): void
    {
        $html = $this->render('Story/Nr', ['headline' => 'H', 'subline' => 'S', 'role' => 'outro', 'slideIndex' => 3, 'slideTotal' => 3, 'sourceLabel' => 'https://example.com/', 'transparent' => false, 'aiLabel' => null]);

        // padding: top horizontal bottom
        self::assertSame(1, preg_match('~\.story\s*\{[^}]*\bpadding:\s*\d+px\s+\d+px\s+(\d+)px\s*;~', $html, $padding), 'three-value padding on .story');
        self::assertSame(1, preg_match('~\.story__header\s*\{[^}]*\btop:\s*(\d+)px~', $html, $top), 'top on .story__header');

        self::assertGreaterThanOrEqual(self::STORY_UI_BAND, (int) $padding[1], 'bottom padding of .story');
        self::assertGreaterThanOrEqual(self::STORY_UI_BAND, (int) $top[1], 'top of .story__header');
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
