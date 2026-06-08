<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Ingestion;

use Netresearch\NrRepurpose\Ingestion\Poppler\SymfonyProcessPopplerRunner;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;

final class SymfonyProcessPopplerRunnerTest extends AbstractFunctionalTestCase
{
    private function fixture(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Pdf/sample-text.pdf';
    }

    public function testRasterizePageReturnsPngBytes(): void
    {
        $bytes = (new SymfonyProcessPopplerRunner())->rasterizePage($this->fixture(), 1, 100);

        // PNG magic number.
        self::assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8));
    }

    public function testExtractLayoutReturnsText(): void
    {
        $text = (new SymfonyProcessPopplerRunner())->extractLayout($this->fixture(), 1);

        self::assertStringContainsString('Net revenue rose to 48 million euro', $text);
    }
}
