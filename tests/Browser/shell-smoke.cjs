/**
 * Browser smoke test for the map-first shell.
 *
 * The bugs this catches are ones no PHP test can see: a JS error that leaves
 * the sheet blank, and a focus-scroll that drags the document and leaves half
 * the screen empty. Both shipped to the user before this existed.
 *
 *   node tests/Browser/shell-smoke.cjs <baseUrl> <riderId> <accessToken>
 */
const puppeteer = require('puppeteer');

const [baseUrl, riderId, token] = process.argv.slice(2);

if (!baseUrl || !riderId || !token) {
    console.error('usage: shell-smoke.cjs <baseUrl> <riderId> <accessToken>');
    process.exit(2);
}

const failures = [];
const check = (name, ok, detail = '') => {
    console.log(`${ok ? '  PASS' : '  FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
    if (!ok) failures.push(name);
};

/** The document must have no scrollable overflow: the sheet scrolls, not the page. */
const pageScroll = (page) => page.evaluate(() => ({
    scrollY: window.scrollY,
    overflow: Math.max(
        document.documentElement.scrollHeight - window.innerHeight,
        document.body.scrollHeight - window.innerHeight,
    ),
}));

(async () => {
    const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });

    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

    // Seed the rider session the same way the feature tests do.
    await page.goto(`${baseUrl}/__test-login?id=${riderId}&token=${encodeURIComponent(token)}`, { waitUntil: 'networkidle2' });

    for (const path of ['/app/ride', '/app/parcel', '/app/rental']) {
        console.log(`\n${path}`);
        errors.length = 0;

        await page.goto(baseUrl + path, { waitUntil: 'networkidle2' });
        await new Promise((r) => setTimeout(r, 1200));

        // 1. The shell actually rendered.
        const sheet = await page.$('.shell-sheet');
        const map = await page.$('.shell-map');
        check('sheet renders', !!sheet);
        check('map renders', !!map);

        // 2. The sheet has real height — a blank shell is the failure the user saw.
        const sheetBox = sheet ? await sheet.boundingBox() : null;
        check('sheet has height', !!sheetBox && sheetBox.height > 200,
            sheetBox ? `${Math.round(sheetBox.height)}px` : 'no box');

        // 3. The page itself must not be scrollable.
        const before = await pageScroll(page);
        check('document not scrollable', before.overflow <= 1, `overflow ${before.overflow}px`);

        // 4. Clicking a payment tile must not scroll the page.
        // Only meaningful when visible — the pickers sit behind x-show="ready",
        // so an invisible tile is skipped rather than silently "passing".
        const tile = await page.$('input[name="payment_method_id"] + span');
        const tileBox = tile ? await tile.boundingBox() : null;
        if (tileBox && tileBox.height > 10) {
            await page.mouse.click(tileBox.x + tileBox.width / 2, tileBox.y + tileBox.height / 2);
            await new Promise((r) => setTimeout(r, 400));
            const after = await pageScroll(page);
            check('payment click does not scroll page', after.scrollY === 0 && after.overflow <= 1,
                `scrollY ${after.scrollY}, overflow ${after.overflow}px`);
        }

        // 5. Same for the coupon list, including the "No coupon" option.
        const couponToggle = await page.$('input[name="coupon_id"]');
        if (couponToggle) {
            await page.evaluate((el) => el.closest('label').click(), couponToggle);
            await new Promise((r) => setTimeout(r, 500));
            const after = await pageScroll(page);
            check('coupon click does not scroll page', after.scrollY === 0 && after.overflow <= 1,
                `scrollY ${after.scrollY}, overflow ${after.overflow}px`);
        }

        check('no JS errors', errors.length === 0, errors.slice(0, 2).join(' | '));
    }

    await browser.close();

    console.log(failures.length ? `\n${failures.length} FAILED` : '\nall checks passed');
    process.exit(failures.length ? 1 : 0);
})().catch((e) => {
    console.error('harness error:', e.message);
    process.exit(3);
});
