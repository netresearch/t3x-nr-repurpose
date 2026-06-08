<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion\Poppler;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Real Poppler invocations via Symfony Process. Binaries (pdftoppm/pdftotext) are baked into
 * the DDEV web image in Plan 1 Task 2 (poppler-utils). No Ghostscript needed (Poppler renders natively).
 */
final class SymfonyProcessPopplerRunner implements PopplerRunnerInterface
{
    private const PROCESS_TIMEOUT = 120.0;

    public function rasterizePage(string $absPdfPath, int $page, int $dpi = 200): string
    {
        $tmpPrefix = sys_get_temp_dir() . '/nrrepurpose_' . bin2hex(random_bytes(6));
        // -singlefile => output is exactly <prefix>.png (no -NN page suffix).
        $process = new Process([
            'pdftoppm', '-png',
            '-r', (string) $dpi,
            '-f', (string) $page,
            '-l', (string) $page,
            '-singlefile',
            $absPdfPath, $tmpPrefix,
        ]);
        $process->setTimeout(self::PROCESS_TIMEOUT);

        $pngPath = $tmpPrefix . '.png';
        try {
            $process->mustRun();
            $bytes = file_get_contents($pngPath);
            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException('pdftoppm produced no PNG for page ' . $page, 1749379430);
            }

            return $bytes;
        } catch (ProcessFailedException $e) {
            throw new \RuntimeException('pdftoppm failed for page ' . $page . ': ' . $e->getMessage(), 1749379431, $e);
        } finally {
            if (is_file($pngPath)) {
                @unlink($pngPath);
            }
        }
    }

    public function extractLayout(string $absPdfPath, int $page): string
    {
        // '-' writes UTF-8 layout-preserved text to stdout.
        $process = new Process([
            'pdftotext', '-layout',
            '-f', (string) $page,
            '-l', (string) $page,
            '-enc', 'UTF-8',
            '-nopgbrk',
            '-q',
            $absPdfPath, '-',
        ]);
        $process->setTimeout(self::PROCESS_TIMEOUT);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new \RuntimeException('pdftotext -layout failed for page ' . $page . ': ' . $e->getMessage(), 1749379432, $e);
        }

        return rtrim($process->getOutput());
    }
}
