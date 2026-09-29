<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion\Poppler;

use Netresearch\NrRepurpose\Exception\PopplerEmptyOutputException;
use Netresearch\NrRepurpose\Exception\PopplerProcessFailedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Real Poppler invocations via Symfony Process. Binaries (pdftoppm/pdftotext) are baked into
 * the DDEV web image in Plan 1 Task 2 (poppler-utils). No Ghostscript needed (Poppler renders natively).
 */
final readonly class SymfonyProcessPopplerRunner implements PopplerRunnerInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private string $pdftoppmBinary = 'pdftoppm',
        private string $pdftotextBinary = 'pdftotext',
        private float $timeoutSeconds = 120.0,
    ) {}

    public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string
    {
        $tmpPrefix = sys_get_temp_dir() . '/nrrepurpose_' . bin2hex(random_bytes(6));
        // -singlefile => output is exactly <prefix>.png (no -NN page suffix).
        $process = new Process([
            $this->pdftoppmBinary, '-png',
            '-r', (string) $dpi,
            '-f', (string) $page,
            '-l', (string) $page,
            '-singlefile',
            $absPdfPath, $tmpPrefix,
        ]);
        $process->setTimeout($this->timeoutSeconds);

        $pngPath = $tmpPrefix . '.png';
        try {
            $process->mustRun();
            $bytes = file_get_contents($pngPath);
            if ($bytes === false || $bytes === '') {
                throw new PopplerEmptyOutputException('pdftoppm produced no PNG for page ' . $page, 1749379430);
            }

            return $bytes;
        } catch (ExceptionInterface $e) {
            throw $this->failed('pdftoppm failed for page ' . $page, 1749379431, $e);
        } finally {
            // $pngPath is an internally-generated temp render path (makeTempDir), never user input.
            if (is_file($pngPath)) {
                @unlink($pngPath); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }
        }
    }

    public function extractLayout(string $absPdfPath, int $page): string
    {
        // '-' writes UTF-8 layout-preserved text to stdout.
        $process = new Process([
            $this->pdftotextBinary, '-layout',
            '-f', (string) $page,
            '-l', (string) $page,
            '-enc', 'UTF-8',
            '-nopgbrk',
            '-q',
            $absPdfPath, '-',
        ]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->mustRun();
        } catch (ExceptionInterface $e) {
            throw $this->failed('pdftotext -layout failed for page ' . $page, 1749379432, $e);
        }

        return rtrim($process->getOutput());
    }

    /**
     * Symfony's message holds the command line (the stored PDF's absolute path) and, for a
     * non-zero exit, the binary's stderr. That holds for a failed run and a failed start as
     * well as for a timeout, which is not a ProcessFailedException. The ingestion error
     * reaches the job's error message, shown to every module user, so the thrown message
     * stays fixed and the detail goes to the server log.
     */
    private function failed(string $message, int $code, ExceptionInterface $e): PopplerProcessFailedException
    {
        $this->logger->error($message, ['exception' => $e]);

        return new PopplerProcessFailedException($message, $code, $e);
    }
}
