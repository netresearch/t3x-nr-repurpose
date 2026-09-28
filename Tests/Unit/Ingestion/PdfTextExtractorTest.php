<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfTextExtractor;
use PHPUnit\Framework\TestCase;

final class PdfTextExtractorTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = __DIR__ . '/../../Fixtures/Pdf/sample-text.pdf';
    }

    public function testExtractsPerPageTextAndMarksDenseTextNotSparse(): void
    {
        $pages = (new PdfTextExtractor())->extract($this->fixture);

        self::assertCount(1, $pages);
        self::assertSame(1, $pages[0]['page']);
        self::assertStringContainsString('Net revenue rose to 48 million euro', $pages[0]['text']);
        self::assertFalse($pages[0]['isSparse']);
    }

    public function testThrowsIngestionExceptionForMissingFile(): void
    {
        $this->expectException(IngestionException::class);
        (new PdfTextExtractor())->extract('/no/such/file.pdf');
    }

    public function testTheErrorsNameNoServerPath(): void
    {
        $missing = sys_get_temp_dir() . '/nrrepurpose-missing-' . bin2hex(random_bytes(4)) . '.pdf';
        $broken  = tempnam(sys_get_temp_dir(), 'nrrepurpose-broken-');
        self::assertIsString($broken);
        file_put_contents($broken, 'not a pdf');

        try {
            foreach ([$missing => 1749379420, $broken => 1749379421] as $path => $code) {
                try {
                    (new PdfTextExtractor())->extract($path);
                    self::fail('Expected an IngestionException for ' . $path);
                } catch (IngestionException $e) {
                    self::assertSame($code, $e->getCode());
                    self::assertStringNotContainsString(sys_get_temp_dir(), $e->getMessage());
                    self::assertStringNotContainsString(basename($path), $e->getMessage());
                }
            }
        } finally {
            unlink($broken);
        }
    }

    public function testTheParserMessageStaysOnThePreviousException(): void
    {
        $broken = tempnam(sys_get_temp_dir(), 'nrrepurpose-broken-');
        self::assertIsString($broken);
        file_put_contents($broken, 'not a pdf');

        try {
            (new PdfTextExtractor())->extract($broken);
            self::fail('A broken file must not parse');
        } catch (IngestionException $e) {
            // Whatever the parser says about the uploaded bytes is logged, not shown.
            self::assertSame('PDF could not be parsed (possibly encrypted or damaged)', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        } finally {
            unlink($broken);
        }
    }
}
