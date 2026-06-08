<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Pipeline;

use Netresearch\NrRepurpose\Domain\ValueObject\ContentBrief;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Pipeline\GenerationContext;
use PHPUnit\Framework\TestCase;

final class GenerationContextTest extends TestCase
{
    private function makeContext(): GenerationContext
    {
        $document = new SourceDocument(
            title: 'Quarterly report',
            text: 'Revenue grew across all regions.',
            sourceLabel: 'https://example.com/report',
            pageCount: 0,
            languageHint: 'en',
        );
        $brief = new ContentBrief('Quarterly report', 'Summary.', ['Point'], [], 'Analysts', 'en');

        return new GenerationContext(
            jobRow: ['uid' => 42, 'theme' => 'nr', 'be_user' => 7, 'want_podcast' => 1],
            document: $document,
            brief: $brief,
            theme: 'nr',
            beUser: 7,
        );
    }

    public function testJobUidReadsTheRawRow(): void
    {
        self::assertSame(42, $this->makeContext()->jobUid());
    }

    public function testExposesBundledState(): void
    {
        $ctx = $this->makeContext();

        self::assertSame('nr', $ctx->theme);
        self::assertSame(7, $ctx->beUser);
        self::assertSame('Quarterly report', $ctx->document->title);
        self::assertSame('en', $ctx->brief->language);
        self::assertSame(1, $ctx->jobRow['want_podcast']);
    }
}
