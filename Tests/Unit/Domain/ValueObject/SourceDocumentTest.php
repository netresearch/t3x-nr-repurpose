<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Domain\ValueObject;

use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use PHPUnit\Framework\TestCase;

final class SourceDocumentTest extends TestCase
{
    public function testExposesConstructorValues(): void
    {
        $doc = new SourceDocument(
            title: 'Annual Report',
            text: 'Body text',
            sourceLabel: 'https://example.com/report',
            pageCount: 12,
            languageHint: 'en',
            meta: ['fetchedVia' => 'chromium'],
        );

        self::assertSame('Annual Report', $doc->title);
        self::assertSame('Body text', $doc->text);
        self::assertSame(12, $doc->pageCount);
        self::assertSame('chromium', $doc->meta['fetchedVia']);
        self::assertFalse($doc->isEmpty());
    }

    public function testIsEmptyForWhitespaceOnlyText(): void
    {
        $doc = new SourceDocument('', "  \n\t ", 'x', 0, '');
        self::assertTrue($doc->isEmpty());
    }
}
