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
 * The visible AI labels (ADR-005), rendered through the real Fluid templates:
 *
 * - the corner label of the four image templates the Chromium renderer turns into PNGs,
 *   present with the setting on and absent with it off;
 * - the "AI-generated" badge of the result view (Partials/Job/AiLabel.html) and where
 *   Show.html uses it;
 * - the closing line next to the FAQ and newsletter content (Partials/Job/TextArtifact.html).
 */
final class AiLabelRenderingTest extends AbstractFunctionalTestCase
{
    private const BADGE = '<div class="ai-label">';

    private const BADGE_PARTIAL = 'Job/AiLabel';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    /** @return array<string, array{0: string}> */
    public static function imageTemplates(): array
    {
        return [
            'Schaubild, Netresearch theme' => ['Schaubild/Nr'],
            'Schaubild, neutral theme'     => ['Schaubild/Neutral'],
            'Story, Netresearch theme'     => ['Story/Nr'],
            'Story, neutral theme'         => ['Story/Neutral'],
        ];
    }

    #[DataProvider('imageTemplates')]
    public function testImageTemplateCarriesTheCornerLabelWhenTheSettingIsOn(string $template): void
    {
        $html = $this->renderImageTemplate($template, 'KI-generiert');

        self::assertSame(1, substr_count($html, self::BADGE . 'KI-generiert</div>'), $html);
    }

    #[DataProvider('imageTemplates')]
    public function testImageTemplateHasNoCornerLabelWhenTheSettingIsOff(string $template): void
    {
        self::assertStringNotContainsString(self::BADGE, $this->renderImageTemplate($template, null));
    }

    #[DataProvider('imageTemplates')]
    public function testTheCornerLabelIsEscaped(string $template): void
    {
        $html = $this->renderImageTemplate($template, '<b>AI</b>');

        self::assertStringContainsString(self::BADGE . '&lt;b&gt;AI&lt;/b&gt;</div>', $html);
    }

    public function testResultViewBadgeNamesTheMarkerWhenTheRowCarriesOne(): void
    {
        $html = $this->renderPartial(self::BADGE_PARTIAL, ['status' => 'done', 'label' => ['aiGenerated' => true]]);

        self::assertStringContainsString('AI-generated', $html);
        self::assertStringContainsString('title="Created with generative AI. The stored artifact carries a machine-readable AI marker."', $html);
    }

    public function testResultViewBadgeForARowStoredBeforeTheMarkerMakesNoMarkerClaim(): void
    {
        $html = $this->renderPartial(self::BADGE_PARTIAL, ['status' => 'done', 'label' => null]);

        self::assertStringContainsString('AI-generated', $html);
        self::assertStringContainsString('title="Created with generative AI."', $html);
        self::assertStringNotContainsString('machine-readable', $html);
    }

    public function testResultViewHasNoBadgeWithoutAResult(): void
    {
        foreach (['failed', 'pending', null] as $status) {
            self::assertStringNotContainsString('AI-generated', $this->renderPartial(self::BADGE_PARTIAL, ['status' => $status]), (string) $status);
        }
    }

    public function testShowLabelsEveryArtifactCardAndTheStoryCard(): void
    {
        $show = (string) file_get_contents(GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Resources/Private/Templates/Job/Show.html'));

        self::assertStringContainsString('<f:render partial="Job/AiLabel" arguments="{status: artifact.status, label: artifact.metadataArray.aiLabel}" />', $show);
        // The story card: labelled from the first finished slide (Job::getDoneStoryArtifact()), so a
        // story whose every slide failed carries no badge.
        self::assertStringContainsString('<f:render partial="Job/AiLabel" arguments="{status: job.doneStoryArtifact.status, label: job.doneStoryArtifact.metadataArray.aiLabel}" />', $show);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function structuredTextFormats(): array
    {
        return [
            'faq'        => ['faq', ['faq' => [['question' => 'Q?', 'answer' => 'A.']]]],
            'newsletter' => ['newsletter', ['subject' => 'S', 'preheader' => 'P', 'paragraphs' => ['Body'], 'callToAction' => 'Go']],
        ];
    }

    /** @param array<string, mixed> $content */
    #[DataProvider('structuredTextFormats')]
    public function testTheClosingLineIsShownNextToStructuredContent(string $type, array $content): void
    {
        $with    = $this->renderText($type, $content + ['closingLine' => 'This text was created with AI.']);
        $without = $this->renderText($type, $content);

        self::assertStringContainsString('Closing line of the copy-ready text:', $with);
        self::assertStringContainsString('This text was created with AI.', $with);
        self::assertStringNotContainsString('Closing line of the copy-ready text:', $without);
    }

    private function renderImageTemplate(string $template, ?string $label): string
    {
        return $this->render('Templates/Generated/' . $template, [
            'title'       => 'Title',
            'bodyHtml'    => '<p>Body</p>',
            'transparent' => false,
            'language'    => 'de',
            'headline'    => 'Headline',
            'subline'     => 'Subline',
            'role'        => 'point',
            'slideIndex'  => 2,
            'slideTotal'  => 3,
            'sourceLabel' => 'https://example.com/',
            'aiLabel'     => $label,
        ]);
    }

    /** @param array<string, mixed> $variables */
    private function renderPartial(string $partial, array $variables): string
    {
        return $this->render('Partials/' . $partial, $variables);
    }

    /** @param array<string, mixed> $content */
    private function renderText(string $type, array $content): string
    {
        $artifact = new Artifact();
        $artifact->_setProperty('type', $type);
        $artifact->_setProperty('status', 'done');
        $artifact->_setProperty('scriptText', 'Text');
        $artifact->_setProperty('metadata', json_encode(['content' => $content], JSON_THROW_ON_ERROR));

        return $this->render('Partials/Job/TextArtifact', ['artifact' => $artifact]);
    }

    /** @param array<string, mixed> $variables */
    private function render(string $path, array $variables): string
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templatePathAndFilename: GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Resources/Private/' . $path . '.html'),
        ));
        $view->assignMultiple($variables);

        return $view->render();
    }
}
