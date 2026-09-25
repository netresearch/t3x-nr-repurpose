<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The New-job form's checkbox defaults: the media artifacts are pre-checked, the four
 * text formats are not. newAction() passes no Job object, so the `checked` attribute
 * alone decides what an editor who just clicks "Create" requests.
 */
final class NewJobFormDefaultsTest extends TestCase
{
    private function checkboxTag(string $property): string
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../Resources/Private/Templates/Job/New.html');
        self::assertSame(1, preg_match('/<f:form\.checkbox\s[^>]*property="' . $property . '"[^>]*\/>/', $template, $match), $property);

        return $match[0];
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function checkboxes(): array
    {
        return [
            'podcast'           => ['wantPodcast', true],
            'schaubild'         => ['wantSchaubild', true],
            'story'             => ['wantStory', true],
            'executive summary' => ['wantExecSummary', false],
            'faq'               => ['wantFaq', false],
            'social posts'      => ['wantSocialPost', false],
            'newsletter'        => ['wantNewsletter', false],
        ];
    }

    #[DataProvider('checkboxes')]
    public function testCheckboxDefault(string $property, bool $checked): void
    {
        $tag = $this->checkboxTag($property);

        if ($checked) {
            self::assertStringContainsString('checked="true"', $tag);
        } else {
            self::assertStringNotContainsString('checked', $tag);
        }
    }
}
