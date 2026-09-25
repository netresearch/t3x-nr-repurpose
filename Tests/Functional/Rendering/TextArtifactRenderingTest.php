<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

use Netresearch\NrRepurpose\Domain\Model\Artifact;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Renders the result-view partial that Show.html uses for the text formats
 * (Partials/Job/TextArtifact.html) with LLM output that carries markup in every field.
 * Everything the model wrote must arrive escaped; an f:format.raw added to any of those
 * fields turns this red.
 */
final class TextArtifactRenderingTest extends AbstractFunctionalTestCase
{
    private const PAYLOAD = '<script>alert(1)</script>';

    private const ESCAPED = '&lt;script&gt;alert(1)&lt;/script&gt;';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    public function testShowRendersTheTextFormatsThroughThisPartial(): void
    {
        $show = (string) file_get_contents(GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Resources/Private/Templates/Job/Show.html'));

        self::assertStringContainsString('<f:render partial="Job/TextArtifact" arguments="{artifact: artifact}" />', $show);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: int}> */
    public static function artifacts(): array
    {
        $p = self::PAYLOAD;

        return [
            'executive summary' => ['exec_summary', ['sentences' => [$p]], 1],
            'faq'               => ['faq', ['faq' => [['question' => $p, 'answer' => $p]], 'jsonLd' => $p], 3],
            'social post'       => ['social_post', ['text' => $p, 'length' => 25, 'maxChars' => 280, 'truncated' => false, 'cutMode' => ''], 1],
            'newsletter'        => ['newsletter', ['subject' => $p, 'preheader' => $p, 'paragraphs' => [$p], 'callToAction' => $p], 4],
        ];
    }

    /** @param array<string, mixed> $content */
    #[DataProvider('artifacts')]
    public function testLlmOutputIsEscaped(string $type, array $content, int $expectedOccurrences): void
    {
        $html = $this->render($type, $content, self::PAYLOAD);

        self::assertStringNotContainsString('<script', $html);
        self::assertSame($expectedOccurrences, substr_count($html, self::ESCAPED), $html);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: list<string>}> */
    public static function socialNotes(): array
    {
        $base = ['text' => 'Post', 'length' => 4, 'maxChars' => 280];

        return [
            'not cut'                   => [$base + ['truncated' => false, 'cutMode' => ''], '', ['boundary', 'left out']],
            'cut at a sentence end'     => [$base + ['truncated' => true, 'cutMode' => 'sentence'], 'Shortened at a sentence boundary', ['word boundary']],
            'cut at a word boundary'    => [$base + ['truncated' => true, 'cutMode' => 'word'], 'Shortened mid-sentence at a word boundary', ['sentence boundary']],
            'row stored before cutMode' => [$base + ['truncated' => true], 'Shortened at a sentence boundary', ['word boundary']],
            'hashtags left out'         => [$base + ['truncated' => false, 'cutMode' => '', 'hashtagsDropped' => 7], '7 hashtags left out to fit.', ['boundary']],
        ];
    }

    /**
     * @param array<string, mixed> $content
     * @param list<string>         $absent
     */
    #[DataProvider('socialNotes')]
    public function testSocialPostNotes(array $content, string $expected, array $absent): void
    {
        $html = $this->render('social_post', $content, 'Post');

        if ($expected !== '') {
            self::assertStringContainsString($expected, $html);
        }

        foreach ($absent as $text) {
            self::assertStringNotContainsString($text, $html);
        }
    }

    /** @param array<string, mixed> $content */
    private function render(string $type, array $content, string $scriptText): string
    {
        $artifact = new Artifact();
        $artifact->_setProperty('type', $type);
        $artifact->_setProperty('status', 'done');
        $artifact->_setProperty('scriptText', $scriptText);
        $artifact->_setProperty('metadata', json_encode(['content' => $content], JSON_THROW_ON_ERROR));

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templatePathAndFilename: GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Resources/Private/Partials/Job/TextArtifact.html'),
        ));
        $view->assign('artifact', $artifact);

        return $view->render();
    }
}
