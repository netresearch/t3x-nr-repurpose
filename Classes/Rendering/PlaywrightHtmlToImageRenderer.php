<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

use Netresearch\NrRepurpose\Rendering\Process\ProcessRunnerInterface;

/**
 * Renders an HTML string to a PNG file by driving Resources/Private/NodeRenderer/render.cjs
 * through Symfony Process. HTML is fed on stdin (avoids argv length limits / shell quoting);
 * chromium is the apt binary at $chromiumPath, exported into the process env as CHROMIUM_PATH
 * (render.cjs reads it from env, not argv). $height=null renders auto-height (fullPage);
 * a fixed $height clips the screenshot to the viewport. $transparent uses omitBackground —
 * the supplied CSS must set html,body{background:transparent} for it to take effect.
 */
final class PlaywrightHtmlToImageRenderer implements HtmlToImageRendererInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly string $nodeBinary = 'node',
        private readonly string $scriptPath = '',
        private readonly string $outputDir = '',
        private readonly string $chromiumPath = '/usr/bin/chromium',
        private readonly float $timeoutSeconds = 60.0,
    ) {}

    public function render(
        string $html,
        int $width,
        ?int $height,
        float $deviceScaleFactor = 1.0,
        bool $transparent = false,
    ): string {
        $dir = rtrim($this->outputDir, '/');
        if ($dir === '') {
            $dir = sys_get_temp_dir();
        }
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw RenderingException::because('Render output dir not writable: ' . $dir, 1749400100);
        }

        $out = $dir . '/' . bin2hex(random_bytes(8)) . '.png';

        // Default the renderer script to the extension's bundled render.cjs (root-package layout:
        // Classes/Rendering -> extension root -> Resources/Private/NodeRenderer/render.cjs).
        $script = $this->scriptPath !== ''
            ? $this->scriptPath
            : dirname(__DIR__, 2) . '/Resources/Private/NodeRenderer/render.cjs';

        $command = [
            $this->nodeBinary,
            $script,
            '--width', (string) $width,
            '--height', $height === null ? 'auto' : (string) $height,
            '--scale', $this->formatScale($deviceScaleFactor),
            '--out', $out,
            $transparent ? '--transparent' : '--opaque',
        ];

        // CHROMIUM_PATH is passed via the process environment (render.cjs reads it from env).
        $previousChromiumPath = getenv('CHROMIUM_PATH');
        putenv('CHROMIUM_PATH=' . $this->chromiumPath);
        try {
            $result = $this->processRunner->run($command, $html, $this->timeoutSeconds);
        } finally {
            putenv($previousChromiumPath === false ? 'CHROMIUM_PATH' : 'CHROMIUM_PATH=' . $previousChromiumPath);
        }

        if (!$result->successful()) {
            throw RenderingException::because(
                sprintf('HTML render failed (exit %d): %s', $result->exitCode, trim($result->stderr)),
                1749400101,
            );
        }
        if (!is_file($out)) {
            throw RenderingException::because('Renderer produced no PNG at ' . $out, 1749400102);
        }

        return $out;
    }

    /** Render a float scale without a trailing ".0" so argv matches the integer-looking common case. */
    private function formatScale(float $scale): string
    {
        return $scale === (float) (int) $scale ? (string) (int) $scale : (string) $scale;
    }
}
