<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

use Netresearch\NrRepurpose\Rendering\Process\ProcessRunnerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Renders an HTML string to a PNG file, or to a PDF (renderPdf()), by driving
 * Resources/Private/NodeRenderer/render.cjs through Symfony Process. HTML is fed on stdin (avoids argv length limits / shell quoting);
 * chromium is the apt binary at $chromiumPath, passed to the child's environment as CHROMIUM_PATH
 * (render.cjs reads it from env, not argv). $height=null renders auto-height (fullPage);
 * a fixed $height clips the screenshot to the viewport. $transparent uses omitBackground —
 * the supplied CSS must set html,body{background:transparent} for it to take effect.
 *
 * With the extension setting `chromiumSandbox` on, render.cjs starts Chromium with its
 * sandbox (`--sandbox`); off, the default, without it. The sandbox needs user namespaces
 * or the setuid sandbox helper on the worker host, which a container started with the
 * default seccomp profile does not provide: there Chromium refuses to start.
 *
 * Failure messages are fixed texts: they reach the artifact's error_message, which every
 * module user sees, while stderr and paths go to the server log only.
 */
final readonly class PlaywrightHtmlToImageRenderer implements HtmlToImageRendererInterface, HtmlToPdfRendererInterface
{
    public function __construct(
        private ProcessRunnerInterface $processRunner,
        private LoggerInterface $logger,
        private string $nodeBinary = 'node',
        private string $scriptPath = '',
        private string $outputDir = '',
        private string $chromiumPath = '/usr/bin/chromium',
        private float $timeoutSeconds = 60.0,
        private ?ExtensionConfiguration $extensionConfiguration = null,
    ) {}

    public function render(
        string $html,
        int $width,
        ?int $height,
        float $deviceScaleFactor = 1.0,
        bool $transparent = false,
    ): string {
        return $this->run(
            $html,
            'png',
            [
                '--width', (string) $width,
                '--height', $height === null ? 'auto' : (string) $height,
                '--scale', $this->formatScale($deviceScaleFactor),
            ],
            [$transparent ? '--transparent' : '--opaque'],
        );
    }

    public function renderPdf(string $html, int $viewportWidth): string
    {
        return $this->run($html, 'pdf', ['--width', (string) $viewportWidth], ['--opaque', '--pdf']);
    }

    /**
     * @param list<string> $arguments render.cjs arguments before --out
     * @param list<string> $flags     render.cjs arguments after --out
     */
    private function run(string $html, string $extension, array $arguments, array $flags): string
    {
        $dir = rtrim($this->outputDir, '/');
        if ($dir === '') {
            $dir = sys_get_temp_dir();
        }

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            $this->logger->error('Render output dir not writable', ['path' => $dir]);

            throw RenderingException::because('Render output dir not writable', 1749400100);
        }

        $out = $dir . '/' . bin2hex(random_bytes(8)) . '.' . $extension;

        // Default the renderer script to the extension's bundled render.cjs (root-package layout:
        // Classes/Rendering -> extension root -> Resources/Private/NodeRenderer/render.cjs).
        $script = $this->scriptPath !== ''
            ? $this->scriptPath
            : dirname(__DIR__, 2) . '/Resources/Private/NodeRenderer/render.cjs';

        if ($this->sandboxEnabled()) {
            $flags[] = '--sandbox';
        }

        $command = [$this->nodeBinary, $script, ...$arguments, '--out', $out, ...$flags];

        // render.cjs reads CHROMIUM_PATH from its environment. It is handed to the runner
        // explicitly: a putenv() here would not reach the child through Symfony Process.
        $result = $this->processRunner->run($command, $html, $this->timeoutSeconds, ['CHROMIUM_PATH' => $this->chromiumPath]);

        if (!$result->successful()) {
            // stderr holds the Chromium launch line (profile directory, script path).
            $this->logger->error('HTML render failed', ['exitCode' => $result->exitCode, 'stderr' => trim($result->stderr)]);

            throw RenderingException::because(sprintf('HTML render failed (exit %d)', $result->exitCode), 1749400101);
        }

        if (!is_file($out)) {
            $this->logger->error('Renderer produced no output file', ['path' => $out]);

            throw RenderingException::because(sprintf('Renderer produced no %s', strtoupper($extension)), 1749400102);
        }

        return $out;
    }

    private function sandboxEnabled(): bool
    {
        try {
            return (bool) $this->extensionConfiguration?->get('nr_repurpose', 'chromiumSandbox');
        } catch (Throwable) {
            // Not configured at all (an installation from before the setting existed).
            return false;
        }
    }

    /** Render a float scale without a trailing ".0" so argv matches the integer-looking common case. */
    private function formatScale(float $scale): string
    {
        return $scale === (float) (int) $scale ? (string) (int) $scale : (string) $scale;
    }
}
