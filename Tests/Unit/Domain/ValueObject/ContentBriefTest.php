<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\ValueObject;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use PHPUnit\Framework\TestCase;

final class ContentBriefTest extends TestCase
{
    public function testExposesAllFieldsAsReadonlyProperties(): void
    {
        $brief = new ContentBrief(
            title: 'Quarterly report',
            summary: 'A concise overview of Q1 results.',
            keyPoints: ['Revenue up 12%', 'Churn down 3%'],
            sections: [
                ['heading' => 'Revenue', 'body' => 'Revenue grew across all regions.'],
                ['heading' => 'Churn', 'body' => 'Churn fell after the new onboarding.'],
            ],
            audience: 'Investors and analysts',
            language: 'en',
        );

        self::assertSame('Quarterly report', $brief->title);
        self::assertSame('A concise overview of Q1 results.', $brief->summary);
        self::assertSame(['Revenue up 12%', 'Churn down 3%'], $brief->keyPoints);
        self::assertSame('Revenue', $brief->sections[0]['heading']);
        self::assertSame('Churn fell after the new onboarding.', $brief->sections[1]['body']);
        self::assertSame('Investors and analysts', $brief->audience);
        self::assertSame('en', $brief->language);
    }

    public function testIsReadonly(): void
    {
        $brief = new ContentBrief('t', 's', [], [], 'a', 'de');

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line intentional readonly violation */
        $brief->title = 'mutated';
    }
}
