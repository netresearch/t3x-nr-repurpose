// CommonJS so it runs without ESM config. Reads HTML from stdin, writes a PNG,
// or with --pdf a PDF whose page size and breaks come from the HTML's CSS (@page).
// argv: --width <int> --height <int|auto> --scale <float> --out <path> (--transparent|--opaque) [--pdf]
const { chromium } = require('playwright-core');

// The HTML carries LLM output derived from a fetched page or PDF, so it is treated as
// hostile: page JavaScript is disabled, and the only requests allowed out are the
// Google Fonts stylesheet and font files the Generated/* templates @import. Everything
// else (images, CSS backgrounds, navigations, favicons) is aborted before it leaves.
const ALLOWED_HOSTS = new Set(['fonts.googleapis.com', 'fonts.gstatic.com']);
const ALLOWED_TYPES = new Set(['stylesheet', 'font']);

function isAllowedRequest(request) {
    let url;
    try {
        url = new URL(request.url());
    } catch (e) {
        return false;
    }
    if (url.protocol === 'data:' || url.protocol === 'blob:') {
        return true;
    }
    return url.protocol === 'https:'
        && ALLOWED_HOSTS.has(url.hostname)
        && ALLOWED_TYPES.has(request.resourceType())
        && request.method() === 'GET';
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
        executablePath: process.env.CHROMIUM_PATH || undefined, // apt chromium
        // The route below does not see every request: Chromium sends <link rel=prefetch>
        // itself, and follows a redirect from an allowed host without asking the route
        // again. So the network layer only reaches the font hosts directly; everything else
        // goes to a proxy address nothing listens on and fails. Playwright adds <-loopback>,
        // so 127.0.0.1 and localhost take the dead proxy too.
        proxy: { server: 'http://127.0.0.1:9', bypass: [...ALLOWED_HOSTS].join(',') },
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
        await page.setContent(html, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts && document.fonts.ready); // wait for webfonts

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
