// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

// CommonJS so it runs without ESM config. Reads HTML from stdin, writes a PNG,
// or with --pdf a PDF whose page size and breaks come from the HTML's CSS (@page).
// argv: --width <int> --height <int|auto> --scale <float> --out <path> (--transparent|--opaque) [--pdf] [--sandbox]
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

// The HTML carries LLM output derived from a fetched page or PDF, so it is treated as
// hostile: page JavaScript is disabled, and no request leaves the renderer. Only data:
// and blob: URLs load; everything else (images, CSS backgrounds, navigations, favicons,
// stylesheets) is aborted, and what the route does not see fails at a dead proxy.
function isAllowedRequest(request) {
    return /^(data|blob):/i.test(request.url());
}

// The Generated/* templates @import their web fonts from Google Fonts. Those rules are
// replaced by @font-face rules over the unmodified font files bundled in
// Resources/Private/Fonts (listed in its fonts.json), inlined as data: URLs, so the
// brand fonts render without network access. A family that is not bundled is dropped
// and falls back like any other unavailable font.
const FONT_DIR = path.join(__dirname, '..', 'Fonts');
const GOOGLE_FONTS_IMPORT = /@import\s+(?:url\(\s*)?(['"]?)(https?:\/\/fonts\.googleapis\.com\/css2?\?[^'")\s]*)\1\s*\)?\s*;?/gi;

function bundledFontFaces(html) {
    let families = null;
    const encoded = new Map();

    return html.replace(GOOGLE_FONTS_IMPORT, (match, quote, href) => {
        families ??= JSON.parse(fs.readFileSync(path.join(FONT_DIR, 'fonts.json'), 'utf8')).families;
        const url = new URL(href.replace(/&amp;/g, '&'));
        const names = url.searchParams.getAll('family')
            .flatMap((value) => value.split('|'))
            .map((value) => value.split(':')[0].trim());

        return names.map((name) => {
            const font = Object.prototype.hasOwnProperty.call(families, name) ? families[name] : null;
            if (font === null) {
                console.error(`render.cjs: web font "${name}" is not bundled, using the fallback font`);
                return '';
            }
            if (!encoded.has(name)) {
                encoded.set(name, fs.readFileSync(path.join(FONT_DIR, font.file)).toString('base64'));
            }
            return `@font-face{font-family:${JSON.stringify(name)};font-style:normal;`
                + `font-weight:${font.weight};font-stretch:${font.stretch};font-display:block;`
                + (font.variationSettings ? `font-variation-settings:${font.variationSettings};` : '')
                + `src:url(data:font/ttf;base64,${encoded.get(name)}) format('truetype')}`;
        }).join('\n');
    });
}

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
    const pdf = process.argv.includes('--pdf');
    // With --sandbox Chromium runs with its sandbox, which needs user namespaces or the
    // setuid helper on the host; without it (the default) Playwright passes --no-sandbox.
    const sandbox = process.argv.includes('--sandbox');

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
        chromiumSandbox: sandbox,
        args: sandbox
            ? ['--force-color-profile=srgb']
            : ['--no-sandbox', '--disable-setuid-sandbox', '--force-color-profile=srgb'],
        executablePath: process.env.CHROMIUM_PATH || undefined, // apt chromium
        // The route below does not see every request: Chromium sends <link rel=prefetch>
        // itself, and follows a redirect without asking the route again. So every request
        // goes to a proxy address nothing listens on and fails. With no bypass list,
        // Playwright adds <-loopback>, so 127.0.0.1 and localhost take the dead proxy too.
        proxy: { server: 'http://127.0.0.1:9' },
    });

    try {
        const context = await browser.newContext({
            viewport: { width, height: heightA === 'auto' ? 10 : parseInt(heightA, 10) },
            deviceScaleFactor: scale, // CONTEXT-level option
            javaScriptEnabled: false, // injected <script>/on* handlers never run
            serviceWorkers: 'block',
        });
        // Registered on the context before the page exists, so no request can slip past it.
        await context.route(() => true, (route) => (
            isAllowedRequest(route.request()) ? route.continue() : route.abort('blockedbyclient')
        ));
        const page = await context.newPage();
        await page.setContent(bundledFontFaces(html), { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts && document.fonts.ready); // wait for the bundled fonts

        if (pdf) {
            // Page size and margins from the template's @page rule; backgrounds printed.
            await page.pdf({ path: out, printBackground: true, preferCSSPageSize: true });
            return;
        }

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
