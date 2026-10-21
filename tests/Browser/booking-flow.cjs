/**
 * Reproduces the real booking path, not a synthetic one.
 *
 * The earlier smoke test clicked payment tiles that were hidden behind
 * x-show="ready" — it measured nothing and passed while the screen was visibly
 * broken. This one sets an actual route first so fares load and the pickers
 * become visible, then uses real mouse clicks (which trigger the focus
 * behaviour that caused the scroll bug) and measures the shell against the
 * viewport.
 *
 *   node tests/Browser/booking-flow.cjs <baseUrl> <riderId> <accessToken>
 */
const puppeteer = require('puppeteer');

const [baseUrl, riderId, token] = process.argv.slice(2);
const failures = [];

const check = (name, ok, detail = '') => {
    console.log(`${ok ? '  PASS' : '  FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
    if (!ok) failures.push(name);
};

const geometry = (page) => page.evaluate(() => {
    const shell = document.querySelector('.shell');
    const sheet = document.querySelector('.shell-sheet');
    const r = shell ? shell.getBoundingClientRect() : null;
    const s = sheet ? sheet.getBoundingClientRect() : null;

    return {
        viewport: window.innerHeight,
        shellTop: r ? Math.round(r.top) : null,
        shellHeight: r ? Math.round(r.height) : null,
        shellBottom: r ? Math.round(r.bottom) : null,
        sheetHeight: s ? Math.round(s.height) : null,
        scrollY: window.scrollY,
        // The scroll that document checks missed for days: an overflow-hidden
        // box is still scrollable PROGRAMMATICALLY, and focusing a hidden
        // radio scrolled .shell itself, cutting the map and forms.
        shellScroll: shell ? Math.round(shell.scrollTop) : 0,
        docOverflow: Math.max(
            document.documentElement.scrollHeight - window.innerHeight,
            document.body.scrollHeight - window.innerHeight,
        ),
    };
});

(async () => {
    const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });

    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await page.goto(`${baseUrl}/__test-login?id=${riderId}&token=${encodeURIComponent(token)}`, { waitUntil: 'networkidle2' });
    await page.goto(`${baseUrl}/app/ride`, { waitUntil: 'networkidle2' });
    await new Promise((r) => setTimeout(r, 1000));

    // Drive the real component: Ahmedabad -> Surat, which is what the user did.
    await page.evaluate(() => {
        const root = document.querySelector('.shell');
        const c = Alpine.$data(root);
        c.setPoint('pickup', 23.0225, 72.5714, 'Ahmedabad');
        c.setPoint('drop', 21.1702, 72.8311, 'Surat');
    });

    // Wait for fares, which is what reveals the payment picker.
    await page.waitForFunction(
        () => Alpine.$data(document.querySelector('.shell')).options.length > 0,
        { timeout: 25000 },
    ).catch(() => {});
    await new Promise((r) => setTimeout(r, 800));

    const quoted = await page.evaluate(() => Alpine.$data(document.querySelector('.shell')).options.length);
    check('fares loaded', quoted > 0, `${quoted} vehicle types`);

    const before = await geometry(page);
    check('shell fills viewport below header',
        before.shellHeight !== null && Math.abs(before.shellBottom - before.viewport) <= 2,
        `shell ${before.shellTop}→${before.shellBottom} of ${before.viewport}`);
    check('document not scrollable', before.docOverflow <= 1, `overflow ${before.docOverflow}px`);

    // The payment picker should now be visible. Click it for real.
    const tile = await page.$('input[name="payment_method_id"] + span');
    const box = tile ? await tile.boundingBox() : null;
    check('payment tile visible', !!box && box.height > 10,
        box ? `${Math.round(box.width)}x${Math.round(box.height)}` : 'not visible');

    if (box) {
        await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
        await new Promise((r) => setTimeout(r, 600));

        const after = await geometry(page);
        check('payment click does not scroll the page',
            after.scrollY === 0 && after.docOverflow <= 1,
            `scrollY ${after.scrollY}, overflow ${after.docOverflow}px`);
        check('payment click does not scroll .shell itself',
            after.shellScroll === 0, `shell scrollTop ${after.shellScroll}`);
        check('shell still fills viewport after click',
            Math.abs(after.shellBottom - after.viewport) <= 2,
            `shell bottom ${after.shellBottom} of ${after.viewport}`);
    }

    // The coupon list must collapse when an option is picked, INCLUDING the
    // already-selected "No coupon". A radio that is already checked fires no
    // change event, which previously left the list open and the sheet stuck
    // at full scroll with no way back to the top.
    const opened = await page.evaluate(() => {
        const btn = [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Apply a coupon'));
        if (!btn) return null;
        btn.scrollIntoView({ block: 'center' });
        btn.click();
        return true;
    });

    if (opened) {
        await new Promise((r) => setTimeout(r, 700));
        const openHeight = await page.evaluate(() => document.querySelector('.shell-sheet-body').scrollHeight);

        const target = await page.evaluate(() => {
            const label = [...document.querySelectorAll('label')].find((l) => l.textContent.trim() === 'No coupon');
            if (!label) return null;
            label.scrollIntoView({ block: 'center' });
            const r = label.getBoundingClientRect();
            return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
        });

        if (target) {
            await page.mouse.click(target.x, target.y);
            await new Promise((r) => setTimeout(r, 1200));

            const closedHeight = await page.evaluate(() => document.querySelector('.shell-sheet-body').scrollHeight);
            check('"No coupon" collapses the list', closedHeight < openHeight, `${openHeight} → ${closedHeight}`);

            // And the sheet must still be scrollable back to the top by wheel.
            await page.mouse.move(230, 400);
            for (let i = 0; i < 12; i++) {
                await page.mouse.wheel({ deltaY: -120 });
                await new Promise((r) => setTimeout(r, 60));
            }
            await new Promise((r) => setTimeout(r, 400));

            const top = await page.evaluate(() => Math.round(document.querySelector('.shell-sheet-body').scrollTop));
            check('sheet scrolls back to the top', top === 0, `scrollTop ${top}`);
        }
    }

    check('no JS errors', errors.length === 0, errors.slice(0, 2).join(' | '));

    await browser.close();
    console.log(failures.length ? `\n${failures.length} FAILED` : '\nall checks passed');
    process.exit(failures.length ? 1 : 0);
})().catch((e) => {
    console.error('harness error:', e.message);
    process.exit(3);
});
