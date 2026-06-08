# nr_repurpose Plan 4 — Shared Render Infrastructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Implement each task in strict TDD order: write the failing test, run it and observe the FAIL, write the complete real code, run the test and observe the PASS, then commit.

**Goal:** Deliver the three shared rendering primitives every generator (Plan 5) depends on, each behind a stable interface and unit-testable without touching a browser, ffmpeg or the GD-rendered network: `HtmlToImageRendererInterface` + `PlaywrightHtmlToImageRenderer` (HTML→PNG via a CommonJS Node script driven through Symfony `Process`), `ImageCompositorInterface` + `GdImageCompositor` (transparent foreground PNG over a background PNG, GD only — Imagick is not installed), and `AudioStitcherInterface` + `FfmpegAudioStitcher` (concat mp3 segments via the ffmpeg concat demuxer, probe duration via ffprobe). A shared `RenderingException` carries the failure context. Real browser/ffmpeg calls are exercised by one functional smoke test apiece; everything else is unit-tested by asserting the constructed `Process` argv against a faked process runner, or by feeding in-memory PNGs to GD.

**Architecture:** Netresearch TYPO3-extension convention (extension repo *is* the Composer root; DDEV installs TYPO3 v14.3 into `.Build/Web/`). This plan adds the `Netresearch\NrRepurpose\Rendering\` namespace from the cross-plan contracts. The renderer and the stitcher both shell out via `Symfony\Component\Process\Process`; to keep them unit-testable the `Process` construction is delegated to an injectable `ProcessRunnerInterface` whose default implementation runs the process and whose test double records the argv and returns a canned result — so a unit test can assert the *exact* command without spawning anything. The Node renderer lives in `Resources/Private/NodeRenderer/render.cjs` (playwright-core ^1.57, chromium via `CHROMIUM_PATH=/usr/bin/chromium`, `--no-sandbox`, HTML on stdin, `networkidle` + `document.fonts.ready`, `fullPage` for auto-height else a clipped viewport, `omitBackground` for transparent). `GdImageCompositor` uses only the `gd` extension (alpha blending + alpha save, resize the foreground to the background dimensions when they differ). Construction args (node binary, script path, output dir, chromium path, ffmpeg/ffprobe binaries) are all injectable so tests stay deterministic. No generator is called here — Plan 5 consumes these interfaces.

**Tech Stack:** PHP 8.3+ (strict_types, final classes, constructor property promotion, readonly VOs/exceptions), TYPO3 v14.3 LTS, `symfony/process` ^7, ext-gd, Node v24 + playwright-core ^1.57 (apt chromium at `/usr/bin/chromium`), ffmpeg + ffprobe + poppler (baked into `.ddev/web-build` since Plan 1 Task 2), `typo3/testing-framework` (unit + functional).

**Spec coverage (this plan):** §7 Render-Infra (`HtmlToImageRenderer`, `ImageCompositor`, `AudioStitcher`), §3 decision log (Render-Engine = headless Chromium/Playwright; Imagick NOT installed → GD; ffmpeg concat for podcast), §9 (`AudioStitcher` ffmpeg concat + `ffprobe` cue timing), §13 (renderer/binary paths configurable), §14 (render/ffmpeg/GD behind interfaces, mocked in unit tests; one real smoke test apiece). NOT in this plan: any generator (Plan 5), themes/templates (Plan 5/6), ingestion (Plan 2), understanding (Plan 3). This plan is independent of Plans 2 and 3 and builds only on Plan 1.

**Key grounded facts** (see `docs/superpowers/grounding/2026-06-08-cross-stack-api-grounding.md`):
- HTML→PNG render path is the **Playwright Node renderer** option (grounding render area, lines 577–658), NOT Browsershot. PHP shells out via `Symfony\Component\Process\Process`, HTML on **stdin** (`setInput`), argv carries `--width/--height/--scale/--out` + `--transparent|--opaque` (grounding lines 602–619).
- `render.cjs` uses `require('playwright-core')`, `chromium.launch({ headless:true, args:['--no-sandbox','--disable-setuid-sandbox','--force-color-profile=srgb'], executablePath: process.env.CHROMIUM_PATH || undefined })`, `newContext({ viewport, deviceScaleFactor })` (deviceScaleFactor is a **context** option), `page.setContent(html,{waitUntil:'networkidle'})`, `page.evaluate(() => document.fonts && document.fonts.ready)`, `page.screenshot({ path, type:'png', fullPage: heightA==='auto', omitBackground: transparent })` (grounding lines 623–658).
- Transparent screenshots require `omitBackground:true` **and** the page CSS itself transparent (`html,body{background:transparent}`); PNG only (grounding fact [8], line 539).
- DDEV web container is Debian 13 trixie with Node v24.15.0/npm 11.12.1; chromium installed via apt at `/usr/bin/chromium`; `CHROMIUM_PATH=/usr/bin/chromium` + `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1` baked into `.ddev/web-build/Dockerfile` (grounding facts [0][1], Plan 1 Task 2; render area lines 560–571).
- Imagick is **NOT** loaded in PHP; only `gd` is present (grounding fact [8], line 725 / line 909 `php -m => gd only, no imagick`). Compositor must use GD.
- ffmpeg + poppler-utils + chromium + fonts-liberation are installed in `.ddev/web-build` (grounding line 1001–1007; Plan 1 Task 2 Dockerfile already bakes ffmpeg/poppler/chromium).
- Functional tests run inside `ddev exec` (the functional DB lives in the DDEV `db` service); unit tests run on the host with `.Build/bin/phpunit -c Build/phpunit/UnitTests.xml` (Plan 1 Tasks 3–7).
- The interface signatures are fixed by the cross-plan contracts doc §Interfaces (Rendering): `HtmlToImageRendererInterface::render(string $html, int $width, ?int $height, float $deviceScaleFactor = 1.0, bool $transparent = false): string`; `ImageCompositorInterface::overlay(string $backgroundPng, string $foregroundPng, string $outPath): string`; `AudioStitcherInterface::concat(array $mp3Paths, string $outPath): string` + `probeDurationSeconds(string $path): float`.

---

## File Structure

**Extension repo root = `/home/sme/p/nr-repurpose/main/`** (paths below are relative to it).

| File | Responsibility |
|---|---|
| `Classes/Rendering/RenderingException.php` | Typed exception for all render-infra failures (HTML→PNG, compositing, audio) |
| `Classes/Rendering/HtmlToImageRendererInterface.php` | Contract: render HTML → PNG path (verbatim from contracts doc) |
| `Classes/Rendering/ImageCompositorInterface.php` | Contract: overlay transparent foreground PNG over background PNG |
| `Classes/Rendering/AudioStitcherInterface.php` | Contract: concat mp3 list + probe duration (seconds) |
| `Classes/Rendering/Process/ProcessRunnerInterface.php` | Injectable boundary around `Symfony\Component\Process\Process`: run argv + optional stdin → result |
| `Classes/Rendering/Process/ProcessResult.php` | Readonly VO: exit code + stdout + stderr |
| `Classes/Rendering/Process/SymfonyProcessRunner.php` | Default runner; builds + runs a `Process`, feeds stdin via `setInput`, returns `ProcessResult` |
| `Classes/Rendering/PlaywrightHtmlToImageRenderer.php` | Builds the `render.cjs` argv, feeds HTML on stdin, returns the PNG path |
| `Classes/Rendering/GdImageCompositor.php` | GD alpha overlay of foreground PNG onto background PNG, resize-to-fit |
| `Classes/Rendering/FfmpegAudioStitcher.php` | ffmpeg concat-demuxer concat + ffprobe duration probe |
| `Resources/Private/NodeRenderer/render.cjs` | CommonJS Playwright renderer (chromium, stdin HTML, fullPage/clip, omitBackground) |
| `Resources/Private/NodeRenderer/package.json` | `playwright-core ^1.57` dependency for the renderer |
| `Configuration/Services.yaml` | Bind interface aliases + constructor scalar args (node/script/out/chromium/ffmpeg paths) — *modified* |
| `.ddev/commands/web/install` | Add `npm ci` of the NodeRenderer deps to the installer — *modified* |
| `Tests/Unit/Rendering/PlaywrightHtmlToImageRendererTest.php` | Asserts the exact `render.cjs` argv + stdin for diagram/story/transparent |
| `Tests/Unit/Rendering/GdImageCompositorTest.php` | Overlays two generated in-memory PNGs, asserts output PNG + dimensions |
| `Tests/Unit/Rendering/FfmpegAudioStitcherTest.php` | Asserts the concat-demuxer + ffprobe argv via a fake runner; parses a fake ffprobe duration |
| `Tests/Unit/Rendering/Fixture/RecordingProcessRunner.php` | Test double recording argv/stdin and returning a canned `ProcessResult` |
| `Tests/Functional/Rendering/PlaywrightHtmlToImageRendererTest.php` | Smoke: render tiny HTML → real PNG; assert PNG signature + dimensions (runs via `ddev exec`) |
| `Tests/Functional/Rendering/FfmpegAudioStitcherTest.php` | Smoke: synth two short fixture mp3s with ffmpeg, concat, assert duration ≈ sum (runs via `ddev exec`) |

---

## Task 1: RenderingException + the three Rendering interfaces

Pure contract definitions, copied verbatim from the cross-plan contracts doc so Plan 5 can type-hint them. A small unit test pins the exception factory and the interface method shapes (reflection) so a later refactor can't silently drift from the contract.

**Files:**
- Create: `Classes/Rendering/RenderingException.php`
- Create: `Classes/Rendering/HtmlToImageRendererInterface.php`, `Classes/Rendering/ImageCompositorInterface.php`, `Classes/Rendering/AudioStitcherInterface.php`
- Test: `Tests/Unit/Rendering/RenderingContractTest.php`

- [ ] **Step 1: Write the failing test `Tests/Unit/Rendering/RenderingContractTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\AudioStitcherInterface;
use Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface;
use Netresearch\NrRepurpose\Rendering\ImageCompositorInterface;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use PHPUnit\Framework\TestCase;

final class RenderingContractTest extends TestCase
{
    public function testExceptionIsRuntimeExceptionWithFactory(): void
    {
        $previous = new \RuntimeException('boom');
        $e = RenderingException::because('render failed', 1749400000, $previous);

        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('render failed', $e->getMessage());
        self::assertSame(1749400000, $e->getCode());
        self::assertSame($previous, $e->getPrevious());
    }

    public function testRendererInterfaceSignatureMatchesContract(): void
    {
        $method = new \ReflectionMethod(HtmlToImageRendererInterface::class, 'render');
        self::assertSame('string', (string) $method->getReturnType());
        $params = $method->getParameters();
        self::assertSame(['html', 'width', 'height', 'deviceScaleFactor', 'transparent'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $params,
        ));
        self::assertTrue($params[2]->allowsNull());          // ?int $height
        self::assertSame(1.0, $params[3]->getDefaultValue()); // deviceScaleFactor
        self::assertFalse($params[4]->getDefaultValue());     // transparent
    }

    public function testCompositorInterfaceSignatureMatchesContract(): void
    {
        $method = new \ReflectionMethod(ImageCompositorInterface::class, 'overlay');
        self::assertSame('string', (string) $method->getReturnType());
        self::assertSame(['backgroundPng', 'foregroundPng', 'outPath'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters(),
        ));
    }

    public function testStitcherInterfaceSignatureMatchesContract(): void
    {
        $concat = new \ReflectionMethod(AudioStitcherInterface::class, 'concat');
        self::assertSame('string', (string) $concat->getReturnType());
        self::assertSame(['mp3Paths', 'outPath'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $concat->getParameters(),
        ));

        $probe = new \ReflectionMethod(AudioStitcherInterface::class, 'probeDurationSeconds');
        self::assertSame('float', (string) $probe->getReturnType());
        self::assertSame(['path'], array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $probe->getParameters(),
        ));
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter RenderingContractTest`
Expected: FAIL — `Error: Class "Netresearch\NrRepurpose\Rendering\RenderingException" not found`.

- [ ] **Step 3: Write `Classes/Rendering/RenderingException.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

/**
 * Raised by render-infra primitives (HTML→PNG rendering, image compositing, audio
 * stitching) when an underlying tool (chromium/ffmpeg/ffprobe/GD) or its inputs fail.
 * Generators (Plan 5) catch this to mark a single artifact failed without aborting siblings.
 */
final class RenderingException extends \RuntimeException
{
    public static function because(string $message, int $code, ?\Throwable $previous = null): self
    {
        return new self($message, $code, $previous);
    }
}
```

- [ ] **Step 4: Write the three interfaces (verbatim from the contracts doc)**

`Classes/Rendering/HtmlToImageRendererInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

interface HtmlToImageRendererInterface
{
    /**
     * @return string absoluter Pfad zur erzeugten PNG. $height=null => Auto-Höhe (fullPage).
     * @throws RenderingException
     */
    public function render(
        string $html,
        int $width,
        ?int $height,
        float $deviceScaleFactor = 1.0,
        bool $transparent = false,
    ): string;
}
```

`Classes/Rendering/ImageCompositorInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

interface ImageCompositorInterface
{
    /**
     * Legt $foregroundPng (transparent) über $backgroundPng (GD; Imagick ist NICHT installiert).
     *
     * @return string absoluter Pfad der komponierten PNG ($outPath)
     * @throws RenderingException
     */
    public function overlay(string $backgroundPng, string $foregroundPng, string $outPath): string;
}
```

`Classes/Rendering/AudioStitcherInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

interface AudioStitcherInterface
{
    /**
     * @param list<string> $mp3Paths fügt in Reihenfolge zu einer mp3 (ffmpeg concat).
     * @return string absoluter Pfad der zusammengefügten mp3 ($outPath)
     * @throws RenderingException
     */
    public function concat(array $mp3Paths, string $outPath): string;

    /**
     * Dauer einer Audiodatei in Sekunden (ffprobe) — für WebVTT-Cue-Zeiten.
     *
     * @throws RenderingException
     */
    public function probeDurationSeconds(string $path): float;
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter RenderingContractTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add Classes/Rendering/RenderingException.php Classes/Rendering/HtmlToImageRendererInterface.php Classes/Rendering/ImageCompositorInterface.php Classes/Rendering/AudioStitcherInterface.php Tests/Unit/Rendering/RenderingContractTest.php
git commit -s -m "Add render-infra interfaces and RenderingException"
```

---

## Task 2: Process boundary (ProcessRunnerInterface, ProcessResult, SymfonyProcessRunner)

Both the renderer and the stitcher shell out. To unit-test the *exact command* without spawning a process, command execution is delegated to a thin `ProcessRunnerInterface`. The default `SymfonyProcessRunner` builds a `Symfony\Component\Process\Process`, feeds optional stdin via `setInput` (the grounded way to pass HTML — avoids argv length limits and shell quoting; grounding line 613), runs it, and returns a `ProcessResult`. Tests inject a recording double instead.

**Files:**
- Create: `Classes/Rendering/Process/ProcessResult.php`, `Classes/Rendering/Process/ProcessRunnerInterface.php`, `Classes/Rendering/Process/SymfonyProcessRunner.php`
- Test: `Tests/Unit/Rendering/ProcessResultTest.php`

- [ ] **Step 1: Write the failing test `Tests/Unit/Rendering/ProcessResultTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

final class ProcessResultTest extends TestCase
{
    public function testSuccessfulResultExposesOutputs(): void
    {
        $result = new ProcessResult(0, "5.250000\n", '');

        self::assertTrue($result->successful());
        self::assertSame("5.250000\n", $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testNonZeroExitIsNotSuccessful(): void
    {
        $result = new ProcessResult(1, '', 'ffmpeg: No such file');

        self::assertFalse($result->successful());
        self::assertSame(1, $result->exitCode);
        self::assertSame('ffmpeg: No such file', $result->stderr);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter ProcessResultTest`
Expected: FAIL — `Error: Class "Netresearch\NrRepurpose\Rendering\Process\ProcessResult" not found`.

- [ ] **Step 3: Write `Classes/Rendering/Process/ProcessResult.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering\Process;

/** Result of running an external command via ProcessRunnerInterface. */
final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
```

- [ ] **Step 4: Write `Classes/Rendering/Process/ProcessRunnerInterface.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering\Process;

interface ProcessRunnerInterface
{
    /**
     * Run a command (argv form, no shell) with optional stdin. Never throws on a non-zero
     * exit — the caller inspects ProcessResult and raises a RenderingException with context.
     *
     * @param list<string> $command argv: [binary, arg, ...]
     * @param string|null  $stdin   fed to the process stdin (e.g. HTML for the renderer)
     */
    public function run(array $command, ?string $stdin = null, float $timeoutSeconds = 60.0): ProcessResult;
}
```

- [ ] **Step 5: Write `Classes/Rendering/Process/SymfonyProcessRunner.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering\Process;

use Symfony\Component\Process\Process;

/**
 * Default ProcessRunnerInterface: builds a Symfony Process from an argv array (no shell),
 * feeds optional stdin via setInput(), runs it and returns the captured result. Does NOT
 * use mustRun(): a non-zero exit is reported through ProcessResult so callers can attach
 * tool-specific context to a RenderingException.
 */
final class SymfonyProcessRunner implements ProcessRunnerInterface
{
    public function run(array $command, ?string $stdin = null, float $timeoutSeconds = 60.0): ProcessResult
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        if ($stdin !== null) {
            $process->setInput($stdin);
        }
        $exitCode = $process->run();

        return new ProcessResult(
            (int) $exitCode,
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }
}
```

- [ ] **Step 6: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter ProcessResultTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add Classes/Rendering/Process/ProcessResult.php Classes/Rendering/Process/ProcessRunnerInterface.php Classes/Rendering/Process/SymfonyProcessRunner.php Tests/Unit/Rendering/ProcessResultTest.php
git commit -s -m "Add injectable process-runner boundary for render-infra commands"
```

---

## Task 3: Node renderer assets (render.cjs + package.json) and the npm ci install step

The Node script and its `package.json` are static assets. They are wired into the DDEV installer so the renderer's `playwright-core` is present in the web container. The chromium binary itself is already installed by Plan 1 Task 2 (`/usr/bin/chromium`, `CHROMIUM_PATH` + `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1`); `playwright-core` ships no browser, so `npm ci` is cheap.

**Files:**
- Create: `Resources/Private/NodeRenderer/render.cjs`, `Resources/Private/NodeRenderer/package.json`
- Modify: `.ddev/commands/web/install`

- [ ] **Step 1: Write `Resources/Private/NodeRenderer/package.json`**

```json
{
    "name": "nr-repurpose-node-renderer",
    "version": "1.0.0",
    "private": true,
    "description": "HTML to PNG renderer for nr_repurpose (Playwright + apt chromium)",
    "engines": {
        "node": ">=22.18.0 <25.0.0"
    },
    "dependencies": {
        "playwright-core": "^1.57.0"
    }
}
```

- [ ] **Step 2: Write `Resources/Private/NodeRenderer/render.cjs`** (grounded Playwright renderer, lines 623–658)

```javascript
// CommonJS so it runs without ESM config. Reads HTML from stdin, writes a PNG.
// argv: --width <int> --height <int|auto> --scale <float> --out <path> (--transparent|--opaque)
const { chromium } = require('playwright-core');

function arg(name, def) {
    const i = process.argv.indexOf('--' + name);
    return i > -1 ? process.argv[i + 1] : def;
}

(async () => {
    const width = parseInt(arg('width', '1200'), 10);
    const heightA = arg('height', 'auto');
    const scale = parseFloat(arg('scale', '1'));
    const out = arg('out');
    const transparent = process.argv.includes('--transparent');

    if (!out) {
        console.error('render.cjs: missing --out');
        process.exit(2);
    }

    const html = await new Promise((resolve) => {
        let data = '';
        process.stdin.on('data', (chunk) => { data += chunk; });
        process.stdin.on('end', () => resolve(data));
    });

    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--force-color-profile=srgb'],
        executablePath: process.env.CHROMIUM_PATH || undefined, // apt chromium (B2)
    });

    try {
        const context = await browser.newContext({
            viewport: { width, height: heightA === 'auto' ? 10 : parseInt(heightA, 10) },
            deviceScaleFactor: scale, // CONTEXT-level option
        });
        const page = await context.newPage();
        await page.setContent(html, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts && document.fonts.ready); // wait for webfonts

        await page.screenshot({
            path: out,
            type: 'png',
            fullPage: heightA === 'auto',  // auto-height diagram -> fullPage; fixed story -> clipped to viewport
            omitBackground: transparent,   // transparent PNG; CSS must set html,body{background:transparent}
        });
    } finally {
        await browser.close();
    }
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
```

- [ ] **Step 3: Add the `npm ci` step to `.ddev/commands/web/install`**

Read the current installer first (it was created in Plan 1 Task 2), then insert the NodeRenderer dependency install just before the final `cache:flush`/echo line. The block to add:

```bash
# Render-infra (Plan 4): install the Node renderer's playwright-core into the web container.
# Chromium itself is the apt binary baked by the web-build Dockerfile (CHROMIUM_PATH=/usr/bin/chromium),
# and PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 keeps playwright-core from fetching its own browser.
if [ -f Classes/../Resources/Private/NodeRenderer/package.json ]; then
  ( cd Resources/Private/NodeRenderer && npm ci --no-audit --no-fund )
fi
```

Apply it with an exact-match Edit against the existing installer. The installer's last two lines created in Plan 1 are:
```bash
.Build/bin/typo3 cache:flush
echo "Done. Backend: https://nr-repurpose.ddev.site/typo3/ (admin / Demo1234!)"
```
Insert the `npm ci` block immediately *before* the `.Build/bin/typo3 cache:flush` line so the renderer deps exist after a fresh `ddev install`.

- [ ] **Step 4: Verify the Node script is syntactically valid and the renderer deps install**

Run (inside the running DDEV web container; Docker must be up — see Plan 1 Task 2 Docker note):
```bash
cd /home/sme/p/nr-repurpose/main
ddev exec "cd Resources/Private/NodeRenderer && npm ci --no-audit --no-fund && node --check render.cjs && echo RENDER_CJS_OK"
```
Expected: ends with `RENDER_CJS_OK` (npm installs playwright-core; `node --check` reports no syntax error).

- [ ] **Step 5: Verify chromium is reachable through the script with a trivial render**

Run:
```bash
cd /home/sme/p/nr-repurpose/main
ddev exec "printf '<!doctype html><body style=\"margin:0\"><div style=\"width:40px;height:40px;background:#2F99A4\"></div>' | CHROMIUM_PATH=/usr/bin/chromium node Resources/Private/NodeRenderer/render.cjs --width 40 --height 40 --scale 1 --out /tmp/render-smoke.png --opaque && head -c 8 /tmp/render-smoke.png | od -An -tx1"`
```
Expected: prints the PNG magic bytes `89 50 4e 47 0d 0a 1a 0a` (an actual PNG was produced by chromium). This confirms the apt chromium + playwright-core path works end to end before any PHP wraps it.

- [ ] **Step 6: Commit**

```bash
git add Resources/Private/NodeRenderer/render.cjs Resources/Private/NodeRenderer/package.json .ddev/commands/web/install
git commit -s -m "Add Playwright Node HTML-to-PNG renderer assets and npm ci install step"
```

---

## Task 4: PlaywrightHtmlToImageRenderer (unit-tested via the recording runner)

The PHP renderer builds the `render.cjs` argv from the injected node binary + script path + output dir, feeds the HTML on stdin, and returns the produced PNG path. The unit test injects a `RecordingProcessRunner` and asserts the **exact** argv and stdin for the diagram case (auto-height, @2x, transparent) and the story case (fixed 1080×1920, opaque), plus that a non-zero exit raises a `RenderingException`.

**Files:**
- Create: `Tests/Unit/Rendering/Fixture/RecordingProcessRunner.php`
- Create: `Classes/Rendering/PlaywrightHtmlToImageRenderer.php`
- Test: `Tests/Unit/Rendering/PlaywrightHtmlToImageRendererTest.php`

- [ ] **Step 1: Write the test double `Tests/Unit/Rendering/Fixture/RecordingProcessRunner.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering\Fixture;

use Netresearch\NrRepurpose\Rendering\Process\ProcessResult;
use Netresearch\NrRepurpose\Rendering\Process\ProcessRunnerInterface;

/**
 * Records the argv/stdin of each run() call and returns canned results in order, so unit
 * tests can assert the exact command an infra primitive builds without spawning a process.
 */
final class RecordingProcessRunner implements ProcessRunnerInterface
{
    /** @var list<array{command: list<string>, stdin: ?string, timeout: float}> */
    public array $calls = [];

    /** @var list<ProcessResult> */
    private array $results;

    public function __construct(ProcessResult ...$results)
    {
        $this->results = $results === [] ? [new ProcessResult(0, '', '')] : array_values($results);
    }

    public function run(array $command, ?string $stdin = null, float $timeoutSeconds = 60.0): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'stdin' => $stdin, 'timeout' => $timeoutSeconds];

        return $this->results[count($this->calls) - 1] ?? $this->results[count($this->results) - 1];
    }
}
```

- [ ] **Step 2: Write the failing test `Tests/Unit/Rendering/PlaywrightHtmlToImageRendererTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\PlaywrightHtmlToImageRenderer;
use Netresearch\NrRepurpose\Rendering\Process\ProcessResult;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Tests\Unit\Rendering\Fixture\RecordingProcessRunner;
use PHPUnit\Framework\TestCase;

final class PlaywrightHtmlToImageRendererTest extends TestCase
{
    private const NODE = '/usr/bin/node';
    private const SCRIPT = '/app/Resources/Private/NodeRenderer/render.cjs';
    private const OUT_DIR = '/tmp/nrrepurpose-render';
    private const CHROMIUM = '/usr/bin/chromium';

    private function renderer(RecordingProcessRunner $runner): PlaywrightHtmlToImageRenderer
    {
        return new PlaywrightHtmlToImageRenderer($runner, self::NODE, self::SCRIPT, self::OUT_DIR, self::CHROMIUM);
    }

    public function testDiagramRenderBuildsAutoHeightTransparentArgvAndFeedsHtmlOnStdin(): void
    {
        $runner = new RecordingProcessRunner();
        $out = $this->renderer($runner)->render('<html><body>diagram</body></html>', 1200, null, 2.0, true);

        self::assertCount(1, $runner->calls);
        $call = $runner->calls[0];

        // binary + script first, then the flag pairs, then the transparency flag last.
        self::assertSame(self::NODE, $call['command'][0]);
        self::assertSame(self::SCRIPT, $call['command'][1]);
        self::assertSame(
            ['--width', '1200', '--height', 'auto', '--scale', '2', '--out', $out, '--transparent'],
            array_slice($call['command'], 2),
        );
        self::assertSame('<html><body>diagram</body></html>', $call['stdin']);
        self::assertStringStartsWith(self::OUT_DIR . '/', $out);
        self::assertStringEndsWith('.png', $out);
    }

    public function testStoryRenderBuildsFixedHeightOpaqueArgv(): void
    {
        $runner = new RecordingProcessRunner();
        $out = $this->renderer($runner)->render('<html></html>', 1080, 1920, 1.0, false);

        self::assertSame(
            ['--width', '1080', '--height', '1920', '--scale', '1', '--out', $out, '--opaque'],
            array_slice($runner->calls[0]['command'], 2),
        );
    }

    public function testChromiumPathIsPassedToTheProcessEnvironmentViaArgvIsNotUsed(): void
    {
        // CHROMIUM_PATH is exported into the process env by the runner construction,
        // so the renderer must keep it out of argv (render.cjs reads it from env).
        $runner = new RecordingProcessRunner();
        $out = $this->renderer($runner)->render('<html></html>', 800, 600, 1.0, false);

        self::assertNotContains(self::CHROMIUM, $runner->calls[0]['command']);
        self::assertStringEndsWith('.png', $out);
    }

    public function testNonZeroExitRaisesRenderingExceptionWithStderr(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(1, '', 'chromium crashed'));

        $this->expectException(RenderingException::class);
        $this->expectExceptionMessageMatches('/chromium crashed/');

        $this->renderer($runner)->render('<html></html>', 1080, 1920, 1.0, false);
    }
}
```

- [ ] **Step 3: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter PlaywrightHtmlToImageRendererTest`
Expected: FAIL — `Error: Class "Netresearch\NrRepurpose\Rendering\PlaywrightHtmlToImageRenderer" not found`.

- [ ] **Step 4: Write `Classes/Rendering/PlaywrightHtmlToImageRenderer.php`**

```php
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

        $command = [
            $this->nodeBinary,
            $this->scriptPath,
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
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter PlaywrightHtmlToImageRendererTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add Classes/Rendering/PlaywrightHtmlToImageRenderer.php Tests/Unit/Rendering/PlaywrightHtmlToImageRendererTest.php Tests/Unit/Rendering/Fixture/RecordingProcessRunner.php
git commit -s -m "Add PlaywrightHtmlToImageRenderer with unit-asserted argv construction"
```

---

## Task 5: GdImageCompositor (real GD, in-memory PNG fixtures)

GD-only overlay (Imagick is not installed — grounding fact [8]). The compositor loads the background and the (transparent) foreground PNG, resizes the foreground to the background dimensions when they differ, copies it with alpha preserved (`imagealphablending(true)` on the destination during the copy, `imagesavealpha(true)` before writing), and writes a PNG. This unit test runs real GD against two PNGs generated in memory in `setUp`, so no fixture files and no external process are needed.

**Files:**
- Create: `Classes/Rendering/GdImageCompositor.php`
- Test: `Tests/Unit/Rendering/GdImageCompositorTest.php`

- [ ] **Step 1: Write the failing test `Tests/Unit/Rendering/GdImageCompositorTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\GdImageCompositor;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use PHPUnit\Framework\TestCase;

final class GdImageCompositorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        if (!\extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is required for the compositor');
        }
        $this->tmpDir = sys_get_temp_dir() . '/nrrepurpose-gd-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    /** Solid opaque PNG of the given size/colour. */
    private function makeOpaquePng(int $w, int $h, int $r, int $g, int $b): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        $path = $this->tmpDir . '/bg-' . bin2hex(random_bytes(3)) . '.png';
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    /** Transparent PNG with a single opaque pixel at (0,0) — the "text layer". */
    private function makeMostlyTransparentPng(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); // fully transparent
        imagesetpixel($im, 0, 0, imagecolorallocate($im, 255, 0, 0));      // one opaque red pixel
        $path = $this->tmpDir . '/fg-' . bin2hex(random_bytes(3)) . '.png';
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    public function testOverlayKeepsBackgroundWhereForegroundIsTransparentAndPaintsOpaquePixels(): void
    {
        $bg = $this->makeOpaquePng(20, 30, 0, 0, 255);   // blue background
        $fg = $this->makeMostlyTransparentPng(20, 30);   // transparent except red (0,0)
        $out = $this->tmpDir . '/out.png';

        $returned = (new GdImageCompositor())->overlay($bg, $fg, $out);

        self::assertSame($out, $returned);
        self::assertFileExists($out);

        $size = getimagesize($out);
        self::assertNotFalse($size);
        self::assertSame(20, $size[0]);
        self::assertSame(30, $size[1]);

        $result = imagecreatefrompng($out);
        // (0,0) is the opaque red foreground pixel.
        $corner = imagecolorsforindex($result, imagecolorat($result, 0, 0));
        self::assertSame(255, $corner['red']);
        self::assertSame(0, $corner['blue']);
        // (10,15) is transparent foreground -> background blue shows through.
        $centre = imagecolorsforindex($result, imagecolorat($result, 10, 15));
        self::assertSame(255, $centre['blue']);
        self::assertSame(0, $centre['red']);
        imagedestroy($result);
    }

    public function testForegroundIsResizedToBackgroundDimensions(): void
    {
        $bg = $this->makeOpaquePng(40, 40, 10, 20, 30);
        $fg = $this->makeMostlyTransparentPng(8, 8); // smaller than background
        $out = $this->tmpDir . '/out-resized.png';

        (new GdImageCompositor())->overlay($bg, $fg, $out);

        $size = getimagesize($out);
        self::assertNotFalse($size);
        self::assertSame(40, $size[0]);
        self::assertSame(40, $size[1]);
    }

    public function testMissingBackgroundRaisesRenderingException(): void
    {
        $fg = $this->makeMostlyTransparentPng(4, 4);

        $this->expectException(RenderingException::class);
        (new GdImageCompositor())->overlay($this->tmpDir . '/does-not-exist.png', $fg, $this->tmpDir . '/o.png');
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter GdImageCompositorTest`
Expected: FAIL — `Error: Class "Netresearch\NrRepurpose\Rendering\GdImageCompositor" not found`.

- [ ] **Step 3: Write `Classes/Rendering/GdImageCompositor.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

/**
 * Overlays a transparent foreground PNG (the exact HTML-rendered text/label layer) onto a
 * background PNG (an AI-generated image) using GD — Imagick is NOT installed in this stack.
 * The foreground is resized to the background dimensions when they differ; alpha is preserved
 * so transparent foreground areas reveal the background, and the result is written as a PNG.
 */
final class GdImageCompositor implements ImageCompositorInterface
{
    public function overlay(string $backgroundPng, string $foregroundPng, string $outPath): string
    {
        $background = $this->load($backgroundPng);
        try {
            $foreground = $this->load($foregroundPng);
            try {
                $bgWidth = imagesx($background);
                $bgHeight = imagesy($background);
                $fgWidth = imagesx($foreground);
                $fgHeight = imagesy($foreground);

                // Keep the foreground alpha during the copy and in the output.
                imagealphablending($background, true);
                imagesavealpha($background, true);

                if ($fgWidth === $bgWidth && $fgHeight === $bgHeight) {
                    $ok = imagecopy($background, $foreground, 0, 0, 0, 0, $bgWidth, $bgHeight);
                } else {
                    $ok = imagecopyresampled(
                        $background,
                        $foreground,
                        0, 0, 0, 0,
                        $bgWidth, $bgHeight,
                        $fgWidth, $fgHeight,
                    );
                }
                if ($ok === false) {
                    throw RenderingException::because('GD overlay copy failed', 1749400201);
                }

                $dir = \dirname($outPath);
                if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
                    throw RenderingException::because('Compositor output dir not writable: ' . $dir, 1749400202);
                }
                if (imagepng($background, $outPath) === false) {
                    throw RenderingException::because('GD could not write PNG to ' . $outPath, 1749400203);
                }
            } finally {
                imagedestroy($foreground);
            }
        } finally {
            imagedestroy($background);
        }

        return $outPath;
    }

    private function load(string $path): \GdImage
    {
        if (!is_file($path)) {
            throw RenderingException::because('Compositor input PNG not found: ' . $path, 1749400204);
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw RenderingException::because('Compositor input PNG unreadable: ' . $path, 1749400205);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw RenderingException::because('Compositor input is not a valid image: ' . $path, 1749400206);
        }

        return $image;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter GdImageCompositorTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add Classes/Rendering/GdImageCompositor.php Tests/Unit/Rendering/GdImageCompositorTest.php
git commit -s -m "Add GdImageCompositor (GD alpha overlay, foreground resize-to-fit)"
```

---

## Task 6: FfmpegAudioStitcher (unit-tested via the recording runner)

`concat()` writes a temp ffmpeg concat-list file (`file '<abs>'` lines, single-quotes escaped) and runs `ffmpeg -f concat -safe 0 -i <list> -c copy -y <out>`. `probeDurationSeconds()` runs `ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 <path>` and parses the float. The unit test injects the `RecordingProcessRunner` to assert the **exact** argv for both, asserts the concat-list file content, and checks that a fake ffprobe stdout (`"5.250000\n"`) parses to `5.25`; a non-zero exit raises `RenderingException`.

**Files:**
- Create: `Classes/Rendering/FfmpegAudioStitcher.php`
- Test: `Tests/Unit/Rendering/FfmpegAudioStitcherTest.php`

- [ ] **Step 1: Write the failing test `Tests/Unit/Rendering/FfmpegAudioStitcherTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Rendering;

use Netresearch\NrRepurpose\Rendering\FfmpegAudioStitcher;
use Netresearch\NrRepurpose\Rendering\Process\ProcessResult;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Tests\Unit\Rendering\Fixture\RecordingProcessRunner;
use PHPUnit\Framework\TestCase;

final class FfmpegAudioStitcherTest extends TestCase
{
    private const FFMPEG = '/usr/bin/ffmpeg';
    private const FFPROBE = '/usr/bin/ffprobe';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/nrrepurpose-stitch-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o775, true);
        // Real files so the concat list references existing paths.
        file_put_contents($this->tmpDir . '/a.mp3', 'x');
        file_put_contents($this->tmpDir . '/b.mp3', 'y');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function stitcher(RecordingProcessRunner $runner): FfmpegAudioStitcher
    {
        return new FfmpegAudioStitcher($runner, self::FFMPEG, self::FFPROBE, $this->tmpDir);
    }

    public function testConcatBuildsConcatDemuxerArgvAndWritesAQuotedListFile(): void
    {
        $runner = new RecordingProcessRunner();
        $out = $this->tmpDir . '/joined.mp3';
        // ffmpeg won't actually run (fake runner) but it must report the output exists:
        file_put_contents($out, 'z');

        $returned = $this->stitcher($runner)->concat(
            [$this->tmpDir . '/a.mp3', $this->tmpDir . '/b.mp3'],
            $out,
        );

        self::assertSame($out, $returned);
        self::assertCount(1, $runner->calls);
        $argv = $runner->calls[0]['command'];

        self::assertSame(self::FFMPEG, $argv[0]);
        // The concat list path is the 6th argv element ([-f, concat, -safe, 0, -i, <list>]).
        self::assertSame(['-f', 'concat', '-safe', '0', '-i'], array_slice($argv, 1, 5));
        $listPath = $argv[6];
        self::assertSame(['-c', 'copy', '-y', $out], array_slice($argv, 7));

        // The list file content references both inputs as single-quoted absolute paths.
        $list = (string) file_get_contents($listPath);
        self::assertStringContainsString("file '" . $this->tmpDir . "/a.mp3'", $list);
        self::assertStringContainsString("file '" . $this->tmpDir . "/b.mp3'", $list);
    }

    public function testConcatRejectsAnEmptyList(): void
    {
        $this->expectException(RenderingException::class);
        $this->stitcher(new RecordingProcessRunner())->concat([], $this->tmpDir . '/o.mp3');
    }

    public function testConcatFailureExitRaisesRenderingException(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(1, '', 'Invalid data found'));
        $this->expectException(RenderingException::class);
        $this->expectExceptionMessageMatches('/Invalid data found/');
        $this->stitcher($runner)->concat([$this->tmpDir . '/a.mp3'], $this->tmpDir . '/o.mp3');
    }

    public function testProbeDurationBuildsFfprobeArgvAndParsesSeconds(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(0, "5.250000\n", ''));

        $seconds = $this->stitcher($runner)->probeDurationSeconds($this->tmpDir . '/a.mp3');

        self::assertEqualsWithDelta(5.25, $seconds, 0.0001);
        self::assertSame(
            [
                self::FFPROBE,
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $this->tmpDir . '/a.mp3',
            ],
            $runner->calls[0]['command'],
        );
    }

    public function testProbeFailureExitRaisesRenderingException(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(1, '', 'No such file'));
        $this->expectException(RenderingException::class);
        $this->stitcher($runner)->probeDurationSeconds($this->tmpDir . '/missing.mp3');
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter FfmpegAudioStitcherTest`
Expected: FAIL — `Error: Class "Netresearch\NrRepurpose\Rendering\FfmpegAudioStitcher" not found`.

- [ ] **Step 3: Write `Classes/Rendering/FfmpegAudioStitcher.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

use Netresearch\NrRepurpose\Rendering\Process\ProcessRunnerInterface;

/**
 * Concatenates ordered mp3 segments into one mp3 using the ffmpeg concat DEMUXER (stream copy,
 * no re-encode): it writes a temp concat-list file of `file '<abs>'` lines and runs
 * `ffmpeg -f concat -safe 0 -i <list> -c copy -y <out>`. probeDurationSeconds() reads a file's
 * duration via `ffprobe -show_entries format=duration` for WebVTT cue timing. All binaries
 * (ffmpeg/ffprobe) are baked into the DDEV web-build image (Plan 1 Task 2).
 */
final class FfmpegAudioStitcher implements AudioStitcherInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly string $ffmpegBinary = 'ffmpeg',
        private readonly string $ffprobeBinary = 'ffprobe',
        private readonly string $workDir = '',
        private readonly float $timeoutSeconds = 120.0,
    ) {}

    public function concat(array $mp3Paths, string $outPath): string
    {
        if ($mp3Paths === []) {
            throw RenderingException::because('Cannot concat an empty mp3 list', 1749400301);
        }

        $dir = rtrim($this->workDir, '/');
        if ($dir === '') {
            $dir = sys_get_temp_dir();
        }
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw RenderingException::because('Audio work dir not writable: ' . $dir, 1749400302);
        }

        $listPath = $dir . '/concat-' . bin2hex(random_bytes(8)) . '.txt';
        $lines = [];
        foreach ($mp3Paths as $path) {
            // ffmpeg concat-list syntax escapes a single quote as: '\''
            $lines[] = "file '" . str_replace("'", "'\\''", $path) . "'";
        }
        if (file_put_contents($listPath, implode("\n", $lines) . "\n") === false) {
            throw RenderingException::because('Could not write ffmpeg concat list', 1749400303);
        }

        try {
            $result = $this->processRunner->run(
                [
                    $this->ffmpegBinary,
                    '-f', 'concat',
                    '-safe', '0',
                    '-i', $listPath,
                    '-c', 'copy',
                    '-y', $outPath,
                ],
                null,
                $this->timeoutSeconds,
            );
        } finally {
            @unlink($listPath);
        }

        if (!$result->successful()) {
            throw RenderingException::because(
                sprintf('ffmpeg concat failed (exit %d): %s', $result->exitCode, trim($result->stderr)),
                1749400304,
            );
        }
        if (!is_file($outPath)) {
            throw RenderingException::because('ffmpeg produced no output at ' . $outPath, 1749400305);
        }

        return $outPath;
    }

    public function probeDurationSeconds(string $path): float
    {
        $result = $this->processRunner->run(
            [
                $this->ffprobeBinary,
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $path,
            ],
            null,
            $this->timeoutSeconds,
        );

        if (!$result->successful()) {
            throw RenderingException::because(
                sprintf('ffprobe failed (exit %d): %s', $result->exitCode, trim($result->stderr)),
                1749400306,
            );
        }

        $value = trim($result->stdout);
        if ($value === '' || !is_numeric($value)) {
            throw RenderingException::because('ffprobe returned no numeric duration: ' . $value, 1749400307);
        }

        return (float) $value;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --filter FfmpegAudioStitcherTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add Classes/Rendering/FfmpegAudioStitcher.php Tests/Unit/Rendering/FfmpegAudioStitcherTest.php
git commit -s -m "Add FfmpegAudioStitcher (concat demuxer + ffprobe duration) with unit-asserted argv"
```

---

## Task 7: DI wiring (interface aliases + constructor scalar args)

Bind the three interfaces to their implementations and the `ProcessRunnerInterface` to `SymfonyProcessRunner`, and pass the scalar constructor args (node binary, the absolute `render.cjs` path, the writable output/work dir, chromium path, ffmpeg/ffprobe binaries) so the autowired services resolve. The interfaces remain injectable with overridable scalars for tests.

**Files:**
- Modify: `Configuration/Services.yaml`

- [ ] **Step 1: Read the current `Configuration/Services.yaml`**

It already contains the `_defaults`, the `Netresearch\NrRepurpose\` resource block, and the nr_llm `CapabilityPermissionServiceInterface` public alias (Plan 1 Task 1). Append the render-infra service definitions after the existing entries.

- [ ] **Step 2: Append the render-infra bindings to `Configuration/Services.yaml`**

Add these service entries (exact YAML; `%env(...)` is not used — paths are static in this dev stack, `node`/`ffmpeg`/`ffprobe`/`chromium` resolve on PATH or the apt location):

```yaml
  # Render-infra (Plan 4): process boundary + the three primitives.
  Netresearch\NrRepurpose\Rendering\Process\ProcessRunnerInterface:
    alias: Netresearch\NrRepurpose\Rendering\Process\SymfonyProcessRunner
    public: false

  Netresearch\NrRepurpose\Rendering\HtmlToImageRendererInterface:
    alias: Netresearch\NrRepurpose\Rendering\PlaywrightHtmlToImageRenderer
    public: false

  Netresearch\NrRepurpose\Rendering\ImageCompositorInterface:
    alias: Netresearch\NrRepurpose\Rendering\GdImageCompositor
    public: false

  Netresearch\NrRepurpose\Rendering\AudioStitcherInterface:
    alias: Netresearch\NrRepurpose\Rendering\FfmpegAudioStitcher
    public: false

  Netresearch\NrRepurpose\Rendering\PlaywrightHtmlToImageRenderer:
    arguments:
      $nodeBinary: 'node'
      $scriptPath: '%env(TYPO3_PATH_APP)%/vendor/netresearch/nr-repurpose/Resources/Private/NodeRenderer/render.cjs'
      $outputDir: '%env(TYPO3_PATH_APP)%/var/transient/nr_repurpose/render'
      $chromiumPath: '/usr/bin/chromium'

  Netresearch\NrRepurpose\Rendering\FfmpegAudioStitcher:
    arguments:
      $ffmpegBinary: 'ffmpeg'
      $ffprobeBinary: 'ffprobe'
      $workDir: '%env(TYPO3_PATH_APP)%/var/transient/nr_repurpose/audio'
```

> **Path note:** `TYPO3_PATH_APP` is the TYPO3 application root env var. With Composer-mode installation the extension lives under `vendor/netresearch/nr-repurpose/` and its `Resources/` are NOT symlinked into `public/`; `render.cjs` is read by Node from the package path, so the app-root-relative vendor path is correct. If the build uses a path-repo symlink (DDEV dev), `vendor/netresearch/nr-repurpose` resolves through the symlink to the repo `Resources/Private/NodeRenderer/render.cjs` where `npm ci` placed `node_modules`. The `var/transient/...` dirs are created on demand by the services (`mkdir`).

- [ ] **Step 3: Verify the container compiles and the interfaces resolve**

Run (DDEV up):
```bash
cd /home/sme/p/nr-repurpose/main
ddev exec ".Build/bin/typo3 cache:flush"
ddev exec ".Build/bin/typo3 cache:warmup" && echo CONTAINER_OK
```
Expected: ends with `CONTAINER_OK` (no DI compile error — every render-infra interface alias and the scalar arguments resolve). A missing-argument or unknown-service error would fail here.

- [ ] **Step 4: Commit**

```bash
git add Configuration/Services.yaml
git commit -s -m "Wire render-infra services and interface aliases in DI"
```

---

## Task 8: Functional smoke — real Playwright render + real ffmpeg concat/probe

Two functional tests exercise the *real* binaries inside the DDEV web container (the only place chromium/ffmpeg/ffprobe + node_modules exist). The renderer test renders a tiny HTML and asserts a real PNG with the expected dimensions. The stitcher test synthesizes two short sine-tone mp3s with ffmpeg, concats them, and asserts the probed duration is approximately the sum. These run via `ddev exec`.

**Files:**
- Test: `Tests/Functional/Rendering/PlaywrightHtmlToImageRendererTest.php`
- Test: `Tests/Functional/Rendering/FfmpegAudioStitcherTest.php`

- [ ] **Step 1: Write `Tests/Functional/Rendering/PlaywrightHtmlToImageRendererTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

use Netresearch\NrRepurpose\Rendering\PlaywrightHtmlToImageRenderer;
use Netresearch\NrRepurpose\Rendering\Process\SymfonyProcessRunner;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PlaywrightHtmlToImageRendererTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-repurpose'];

    private function renderer(): PlaywrightHtmlToImageRenderer
    {
        $script = dirname(__DIR__, 3) . '/Resources/Private/NodeRenderer/render.cjs';
        if (!is_file($script) || !is_dir(dirname($script) . '/node_modules')) {
            self::markTestSkipped('NodeRenderer not installed (run npm ci in Resources/Private/NodeRenderer)');
        }
        if (!is_file('/usr/bin/chromium')) {
            self::markTestSkipped('apt chromium not present at /usr/bin/chromium');
        }

        return new PlaywrightHtmlToImageRenderer(
            new SymfonyProcessRunner(),
            'node',
            $script,
            sys_get_temp_dir() . '/nrrepurpose-func-render',
            '/usr/bin/chromium',
        );
    }

    public function testRendersFixedSizeOpaquePng(): void
    {
        $html = '<!doctype html><html><body style="margin:0">'
            . '<div style="width:100px;height:60px;background:#2F99A4"></div></body></html>';

        $out = $this->renderer()->render($html, 100, 60, 1.0, false);

        self::assertFileExists($out);
        $signature = (string) file_get_contents($out, false, null, 0, 8);
        self::assertSame("\x89PNG\r\n\x1a\n", $signature, 'output is a PNG');

        $size = getimagesize($out);
        self::assertNotFalse($size);
        self::assertSame(100, $size[0]);
        self::assertSame(60, $size[1]);

        @unlink($out);
    }

    public function testTransparentAutoHeightRenderProducesPng(): void
    {
        $html = '<!doctype html><html><head><style>html,body{margin:0;background:transparent}</style></head>'
            . '<body><div style="width:80px;height:40px;background:#FF4D00"></div></body></html>';

        $out = $this->renderer()->render($html, 80, null, 1.0, true);

        self::assertFileExists($out);
        $size = getimagesize($out);
        self::assertNotFalse($size);
        self::assertSame(80, $size[0]);
        self::assertGreaterThan(0, $size[1]);

        @unlink($out);
    }
}
```

- [ ] **Step 2: Write `Tests/Functional/Rendering/FfmpegAudioStitcherTest.php`**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

use Netresearch\NrRepurpose\Rendering\FfmpegAudioStitcher;
use Netresearch\NrRepurpose\Rendering\Process\SymfonyProcessRunner;
use Symfony\Component\Process\Process;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FfmpegAudioStitcherTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-repurpose'];

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $ffmpeg = new Process(['ffmpeg', '-version']);
        if ($ffmpeg->run() !== 0) {
            self::markTestSkipped('ffmpeg not available');
        }
        $this->tmpDir = sys_get_temp_dir() . '/nrrepurpose-func-stitch-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /** Generate an mp3 of $seconds of a sine tone via ffmpeg's lavfi source. */
    private function makeTone(string $path, float $seconds): void
    {
        $proc = new Process([
            'ffmpeg', '-y',
            '-f', 'lavfi',
            '-i', 'sine=frequency=440:duration=' . $seconds,
            '-c:a', 'libmp3lame', '-q:a', '9',
            $path,
        ]);
        if ($proc->run() !== 0) {
            self::markTestSkipped('ffmpeg cannot encode mp3 (libmp3lame missing): ' . $proc->getErrorOutput());
        }
    }

    public function testConcatTwoTonesYieldsApproxSummedDuration(): void
    {
        $a = $this->tmpDir . '/a.mp3';
        $b = $this->tmpDir . '/b.mp3';
        $out = $this->tmpDir . '/joined.mp3';
        $this->makeTone($a, 1.0);
        $this->makeTone($b, 2.0);

        $stitcher = new FfmpegAudioStitcher(new SymfonyProcessRunner(), 'ffmpeg', 'ffprobe', $this->tmpDir);

        $result = $stitcher->concat([$a, $b], $out);
        self::assertSame($out, $result);
        self::assertFileExists($out);

        $duration = $stitcher->probeDurationSeconds($out);
        // mp3 frame padding makes this inexact; ~3s with a generous tolerance.
        self::assertEqualsWithDelta(3.0, $duration, 0.5);

        // Each segment probes close to its requested length.
        self::assertEqualsWithDelta(1.0, $stitcher->probeDurationSeconds($a), 0.3);
        self::assertEqualsWithDelta(2.0, $stitcher->probeDurationSeconds($b), 0.3);
    }
}
```

- [ ] **Step 3: Run the functional smoke tests (inside DDEV)**

Run:
```bash
cd /home/sme/p/nr-repurpose/main
ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter 'PlaywrightHtmlToImageRendererTest|FfmpegAudioStitcherTest'"
```
Expected: PASS (4 tests across the two classes; or skipped with a clear reason if chromium/node_modules/ffmpeg are missing — they are present after `ddev install` per Plan 1 Task 2 + this plan Task 3).

> If `node_modules` is absent (fresh checkout that skipped `ddev install`), run `ddev exec "cd Resources/Private/NodeRenderer && npm ci --no-audit --no-fund"` first.

- [ ] **Step 4: Commit**

```bash
git add Tests/Functional/Rendering/PlaywrightHtmlToImageRendererTest.php Tests/Functional/Rendering/FfmpegAudioStitcherTest.php
git commit -s -m "Add functional smoke tests for real Playwright render and ffmpeg concat/probe"
```

---

## Task 9: Full render-infra suite verification

Run the entire unit suite on the host and the render functional tests inside DDEV to confirm the slice is green and isolated.

**Files:** none (verification only).

- [ ] **Step 1: Run all unit tests on the host**

Run: `cd /home/sme/p/nr-repurpose/main && .Build/bin/phpunit -c Build/phpunit/UnitTests.xml`
Expected: PASS — includes `RenderingContractTest` (4), `ProcessResultTest` (2), `PlaywrightHtmlToImageRendererTest` (4), `GdImageCompositorTest` (3), `FfmpegAudioStitcherTest` (5), plus any Plan 1 unit tests. No failures, no risky/deprecation warnings.

- [ ] **Step 2: Run the render functional tests inside DDEV**

Run: `cd /home/sme/p/nr-repurpose/main && ddev exec ".Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml --filter Rendering"`
Expected: PASS (the two smoke tests render a real PNG and concat real mp3s).

- [ ] **Step 3: Confirm no generator/template code leaked into this slice**

Run: `cd /home/sme/p/nr-repurpose/main && grep -RIl "Generator\\\\|Resources/Private/Templates/Generated" Classes/Rendering || echo NO_GENERATOR_COUPLING`
Expected: `NO_GENERATOR_COUPLING` — the Rendering namespace must not reference any generator or theme template (Plan 5 consumes these interfaces, not the reverse).

- [ ] **Step 4: (No commit)** — verification task; nothing new to stage.

---

## Self-Review

**Spec coverage (this plan's slice):**
- §7 Render-Infra — all three primitives delivered: `HtmlToImageRendererInterface`/`PlaywrightHtmlToImageRenderer` (Task 4), `ImageCompositorInterface`/`GdImageCompositor` (Task 5), `AudioStitcherInterface`/`FfmpegAudioStitcher` (Task 6), behind interfaces and mockable (Tasks 4/6 use `RecordingProcessRunner`; Task 5 uses real GD on in-memory PNGs). ✔
- §3 decision log — Render-Engine is headless Chromium via Playwright (Task 3 `render.cjs`); Imagick NOT installed → GD compositor (Task 5); ffmpeg concat for podcast audio (Task 6). ✔
- §9 — `AudioStitcher::concat` (ffmpeg concat demuxer) + `probeDurationSeconds` (ffprobe `format=duration`) for WebVTT cue timing. ✔
- §13 — renderer/binary paths injectable (`PlaywrightHtmlToImageRenderer` and `FfmpegAudioStitcher` ctor args; bound in `Services.yaml`, Task 7). ✔
- §14 — render (chromium), ffmpeg and GD behind interfaces; unit tests mock the process runner / use in-memory PNGs; one real smoke test per external tool (Task 8). ✔
- Out of scope and intentionally absent: any generator, theme template, ingestion, understanding — verified by the Task 9 Step 3 grep.

**Type-consistency vs the cross-plan contracts doc:**
- `HtmlToImageRendererInterface::render(string $html, int $width, ?int $height, float $deviceScaleFactor = 1.0, bool $transparent = false): string` — matches contracts §Rendering verbatim (pinned by `RenderingContractTest`). ✔
- `ImageCompositorInterface::overlay(string $backgroundPng, string $foregroundPng, string $outPath): string` — matches verbatim. ✔
- `AudioStitcherInterface::concat(array $mp3Paths, string $outPath): string` (param documented `@param list<string> $mp3Paths`) + `probeDurationSeconds(string $path): float` — matches verbatim. ✔
- Implementation class names match the contracts doc: `PlaywrightHtmlToImageRenderer`, `GdImageCompositor`, `FfmpegAudioStitcher`, plus `RenderingException`; the Node script path is `Resources/Private/NodeRenderer/render.cjs` (playwright-core, `CHROMIUM_PATH`, `--no-sandbox`, HTML via stdin/setInput) exactly as the contracts doc states. ✔
- `ProcessRunnerInterface`/`ProcessResult`/`SymfonyProcessRunner` are NEW types local to this plan (not in the contracts doc); they are an internal test-seam under `Rendering\Process\` and are not referenced by any cross-plan contract, so they introduce no drift. Plan 5 depends only on the three public Rendering interfaces. ✔
- No table columns are touched by this plan (render-infra is stateless); `tx_repurpose_artifact` columns (`source_html`, `script_text`, `subtitle_file_uid`, `metadata`) remain owned by Plans 1/5. ✔

**Placeholder scan:** No `TODO`/`TBD`/`FIXME`/"similar to above"/"not implemented" anywhere in this plan. Every code step is complete real code (full classes, full `render.cjs`, full tests). Every run step shows the exact command and the expected FAIL/PASS or output (PNG magic bytes, `CONTAINER_OK`, `RENDER_CJS_OK`, `NO_GENERATOR_COUPLING`). Commits use `git commit -s` (DCO), English messages, no AI/bot attribution, no emojis. To self-verify before finishing: `grep -nE "TODO|TBD|FIXME|similar to above|not implemented" docs/superpowers/plans/2026-06-08-nr-repurpose-plan-4-render-infra.md` must return nothing.
