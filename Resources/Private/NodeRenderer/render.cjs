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
        executablePath: process.env.CHROMIUM_PATH || undefined, // apt chromium
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
