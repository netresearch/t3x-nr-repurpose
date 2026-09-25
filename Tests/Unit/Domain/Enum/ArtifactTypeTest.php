<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\Enum;

use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use PHPUnit\Framework\TestCase;

final class ArtifactTypeTest extends TestCase
{
    public function testLabelKeyIsNamespacedByBackedValue(): void
    {
        self::assertSame('artifact.podcast', ArtifactType::Podcast->getLabelKey());
        self::assertSame('artifact.schaubild', ArtifactType::Schaubild->getLabelKey());
        self::assertSame('artifact.story', ArtifactType::Story->getLabelKey());
        self::assertSame('artifact.exec_summary', ArtifactType::ExecutiveSummary->getLabelKey());
        self::assertSame('artifact.faq', ArtifactType::Faq->getLabelKey());
        self::assertSame('artifact.social_post', ArtifactType::SocialPost->getLabelKey());
        self::assertSame('artifact.newsletter', ArtifactType::Newsletter->getLabelKey());
        self::assertSame('artifact.stub', ArtifactType::Stub->getLabelKey());
    }

    public function testIconIdentifierMapsEachTypeToACoreIcon(): void
    {
        self::assertSame('mimetypes-media-audio', ArtifactType::Podcast->getIconIdentifier());
        self::assertSame('content-widget-chart', ArtifactType::Schaubild->getIconIdentifier());
        self::assertSame('actions-device-mobile', ArtifactType::Story->getIconIdentifier());
        self::assertSame('content-text', ArtifactType::ExecutiveSummary->getIconIdentifier());
        self::assertSame('content-accordion', ArtifactType::Faq->getIconIdentifier());
        self::assertSame('actions-share-alt', ArtifactType::SocialPost->getIconIdentifier());
        self::assertSame('actions-envelope', ArtifactType::Newsletter->getIconIdentifier());
        self::assertSame('miscellaneous-placeholder', ArtifactType::Stub->getIconIdentifier());
    }

    /**
     * The artifact `type` column is varchar(16): a longer value is silently truncated
     * (or rejected in strict mode) on MySQL/MariaDB while sqlite stores it whole, so
     * only this check catches it before production.
     */
    public function testEveryValueFitsTheSixteenCharacterTypeColumn(): void
    {
        foreach (ArtifactType::cases() as $type) {
            self::assertLessThanOrEqual(16, strlen($type->value), $type->name);
        }
    }

    public function testEveryLabelKeyIsTranslatedInEnglishAndGerman(): void
    {
        foreach (['locallang.xlf', 'de.locallang.xlf'] as $file) {
            $xliff = (string) file_get_contents(__DIR__ . '/../../../../Resources/Private/Language/' . $file);
            foreach (ArtifactType::cases() as $type) {
                self::assertStringContainsString('id="' . $type->getLabelKey() . '"', $xliff, $file);
            }
        }
    }
}
