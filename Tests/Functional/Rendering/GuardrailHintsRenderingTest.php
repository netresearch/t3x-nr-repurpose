<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

use Netresearch\NrRepurpose\Domain\Model\Artifact;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Renders the warning Show.html puts next to an artifact when nr-llm's guardrails
 * reported on its text (Partials/Job/GuardrailHints.html). A hint's message quotes
 * model output, so it must arrive escaped.
 */
final class GuardrailHintsRenderingTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    /** One card per artifact, and the story card for its slides. */
    public function testShowRendersTheHintsOfEveryArtifactAndOfTheStory(): void
    {
        $show = (string) file_get_contents(GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Resources/Private/Templates/Job/Show.html'));

        self::assertStringContainsString('<f:render partial="Job/GuardrailHints" arguments="{hints: artifact.metadataArray.guardrailHints}" />', $show);
        self::assertStringContainsString('<f:render partial="Job/GuardrailHints" arguments="{hints: job.doneStoryArtifact.metadataArray.guardrailHints}" />', $show);
    }

    public function testEveryHintIsShownWithItsKindAndAnEscapedMessage(): void
    {
        $html = $this->render([
            ['kind' => 'violation', 'message' => 'Breaks "No discounts": <script>alert(1)</script>'],
            ['kind' => 'unavailable', 'message' => 'The rules could not be checked.'],
        ]);

        self::assertStringContainsString('A content rule reported something on this text', $html);
        self::assertStringContainsString('Rule broken:', $html);
        self::assertStringContainsString('Not checked:', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('The rules could not be checked.', $html);
        self::assertSame(2, substr_count($html, '<li'));
    }

    /** From the stored metadata JSON through the Artifact model to the warning, as Show.html calls it. */
    public function testTheHintsStoredOnAnArtifactReachTheWarning(): void
    {
        $artifact = new Artifact();
        $artifact->_setProperty('metadata', json_encode([
            'content'        => ['sentences' => ['Text']],
            'guardrailHints' => [['kind' => 'violation', 'message' => 'Breaks "No discounts"']],
        ], JSON_THROW_ON_ERROR));

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: ['EXT:nr_repurpose/Resources/Private/Partials/'],
            templatePathAndFilename: GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Tests/Functional/Fixtures/Templates/ArtifactGuardrailHints.html'),
        ));
        $view->assign('artifact', $artifact);

        $html = $view->render();

        self::assertStringContainsString('Rule broken:', $html);
        self::assertStringContainsString('Breaks &quot;No discounts&quot;', $html);
    }

    public function testWithoutHintsNothingIsShown(): void
    {
        self::assertSame('', trim($this->render(null)));
        self::assertSame('', trim($this->render([])));
    }

    /** @param list<array{kind: string, message: string}>|null $hints */
    private function render(?array $hints): string
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templatePathAndFilename: GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Resources/Private/Partials/Job/GuardrailHints.html'),
        ));
        $view->assign('hints', $hints);

        return $view->render();
    }
}
