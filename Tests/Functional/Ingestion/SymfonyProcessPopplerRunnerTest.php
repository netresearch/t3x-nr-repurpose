<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Ingestion;

use Netresearch\NrRepurpose\Ingestion\Poppler\SymfonyProcessPopplerRunner;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Process\Process;

final class SymfonyProcessPopplerRunnerTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Same guard pattern as FfmpegAudioStitcherTest: skip where poppler-utils is
        // not installed (e.g. the core-testing images) instead of erroring.
        $poppler = new Process(['pdftoppm', '-v']);
        if ($poppler->run() !== 0) {
            self::markTestSkipped('poppler-utils (pdftoppm/pdftotext) not available');
        }
    }

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
