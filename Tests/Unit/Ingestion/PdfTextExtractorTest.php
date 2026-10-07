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

    public function testReadsAPdfOfExactlyTheMaximumPageCount(): void
    {
        $path = $this->writePdf(3);

        try {
            $pages = (new PdfTextExtractor())->extract($path, 3);
        } finally {
            unlink($path);
        }

        self::assertSame(['Page 1 text', 'Page 2 text', 'Page 3 text'], array_column($pages, 'text'));
    }

    public function testRefusesAPdfWithMorePagesThanTheMaximum(): void
    {
        $path = $this->writePdf(3);

        try {
            (new PdfTextExtractor())->extract($path, 2);
            self::fail('A PDF above the page limit must be refused');
        } catch (IngestionException $e) {
            self::assertSame(1749379454, $e->getCode());
            self::assertSame('The PDF has 3 pages; at most 2 pages are read (extension setting maxPdfPages)', $e->getMessage());
        } finally {
            unlink($path);
        }
    }

    /** A minimal PDF with one line of Helvetica text per page ("Page N text"). */
    private function writePdf(int $pageCount): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $kids = [];
        for ($i = 0; $i < $pageCount; ++$i) {
            $stream              = 'BT /F1 12 Tf 72 720 Td (Page ' . ($i + 1) . ' text) Tj ET';
            $kids[]              = (4 + 2 * $i) . ' 0 R';
            $objects[4 + 2 * $i] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . (5 + 2 * $i) . ' 0 R >>';
            $objects[5 + 2 * $i] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
        ksort($objects);

        $pdf     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'nrrepurpose-pages-');
        self::assertIsString($path);
        file_put_contents($path, $pdf);

        return $path;
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
