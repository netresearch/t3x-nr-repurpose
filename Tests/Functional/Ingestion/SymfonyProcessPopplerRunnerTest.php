<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Ingestion;

use Netresearch\NrRepurpose\Exception\PopplerProcessFailedException;
use Netresearch\NrRepurpose\Ingestion\Poppler\SymfonyProcessPopplerRunner;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

final class SymfonyProcessPopplerRunnerTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Same guard pattern as FfmpegAudioStitcherTest: skip where poppler-utils is
        // not installed (e.g. the core-testing images) instead of erroring. Both
        // binaries are probed — the tests exercise pdftoppm AND pdftotext.
        foreach (['pdftoppm', 'pdftotext'] as $binary) {
            if ((new Process([$binary, '-v']))->run() !== 0) {
                self::markTestSkipped(sprintf('poppler-utils (%s) not available', $binary));
            }
        }
    }

    private function fixture(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Pdf/sample-text.pdf';
    }

    public function testRasterizePageReturnsPngBytes(): void
    {
        $bytes = (new SymfonyProcessPopplerRunner(new NullLogger()))->rasterizePage($this->fixture(), 1, 100);

        // PNG magic number.
        self::assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8));
    }

    public function testExtractLayoutReturnsText(): void
    {
        $text = (new SymfonyProcessPopplerRunner(new NullLogger()))->extractLayout($this->fixture(), 1);

        self::assertStringContainsString('Net revenue rose to 48 million euro', $text);
    }

    /** @return iterable<string, array{string, string}> */
    public static function failingCalls(): iterable
    {
        yield 'rasterizePage' => ['rasterizePage', 'pdftoppm failed for page 1'];
        yield 'extractLayout' => ['extractLayout', 'pdftotext -layout failed for page 1'];
    }

    /**
     * Symfony's ProcessFailedException message holds the command line (with the absolute
     * path of the stored PDF) and the binary's stderr; the ingestion error reaches the job's
     * error message, which every module user sees. It must stay in the server log.
     */
    #[DataProvider('failingCalls')]
    public function testAFailedCallThrowsAFixedMessageAndLogsTheCause(string $method, string $expected): void
    {
        $missing = sys_get_temp_dir() . '/nrrepurpose-secret-dir-' . bin2hex(random_bytes(4)) . '/missing.pdf';
        $logger  = new RecordingLogger();

        try {
            (new SymfonyProcessPopplerRunner($logger))->{$method}($missing, 1);
            self::fail('Expected a PopplerProcessFailedException');
        } catch (PopplerProcessFailedException $e) {
            self::assertSame($expected, $e->getMessage());
            self::assertStringNotContainsString('nrrepurpose-secret-dir', $e->getMessage());
            $inner = $e->getPrevious();
            self::assertNotNull($inner);
            self::assertStringContainsString('nrrepurpose-secret-dir', $inner->getMessage(), 'the cause keeps the detail');
        }

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame($inner, $logger->records[0]['context']['exception'] ?? null);
    }
}
