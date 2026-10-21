/**
 * Walks the parcel wizard end to end in a real browser: route + type on
 * step 1, details on step 2, schedule + payment + recap on step 3 — with the
 * same shell-geometry assertions as the other smokes, since step switching
 * shows/hides focusable fields (the class of change that caused the historic
 * half-blank-screen bug).
 *
 *   node tests/Browser/parcel-wizard.cjs <baseUrl> <riderId> <accessToken>
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
    return {
        viewport: window.innerHeight,
        shellBottom: shell ? Math.round(shell.getBoundingClientRect().bottom) : null,
        scrollY: window.scrollY,
        shellScroll: shell ? Math.round(shell.scrollTop) : 0,
        docOverflow: Math.max(
            document.documentElement.scrollHeight - window.innerHeight,
            document.body.scrollHeight - window.innerHeight,
        ),
    };
});

const wizard = (page) => page.evaluate(() => {
    const form = document.querySelector('form.shell-sheet');
    return Alpine.$data(form).step;
});

(async () => {
    const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });

    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await page.goto(`${baseUrl}/__test-login?id=${riderId}&token=${encodeURIComponent(token)}`, { waitUntil: 'networkidle2' });
    await page.goto(`${baseUrl}/app/parcel`, { waitUntil: 'networkidle2' });
    await new Promise((r) => setTimeout(r, 1000));

    check('starts on step 1', await wizard(page) === 1);

    await page.evaluate(() => {
        const c = Alpine.$data(document.querySelector('.shell'));
        c.setPoint('pickup', 23.0225, 72.5714, 'Ahmedabad');
        c.setPoint('drop', 21.1702, 72.8311, 'Surat');
    });

    await page.waitForFunction(
        () => Alpine.$data(document.querySelector('.shell')).options.length > 0,
        { timeout: 25000 },
    ).catch(() => {});
    await new Promise((r) => setTimeout(r, 500));

    const priced = await page.evaluate(() => Alpine.$data(document.querySelector('.shell')).options.length);
    check('parcel categories priced', priced > 0, `${priced} categories`);

    // Continue is a real click — its target only shows on steps 1-2.
    const clickContinue = async () => {
        const buttons = await page.$$('button');
        for (const b of buttons) {
            const label = await b.evaluate((el) => el.textContent.trim());
            const box = await b.boundingBox();
            if (box && /Continue/.test(label)) {
                await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
                return true;
            }
        }
        return false;
    };

    check('continue visible on step 1', await clickContinue());
    await new Promise((r) => setTimeout(r, 400));
    check('advances to step 2', await wizard(page) === 2);

    const g2 = await geometry(page);
    check('step 2 keeps the shell pinned', g2.scrollY === 0 && g2.shellScroll === 0 && g2.docOverflow <= 1,
        `scrollY ${g2.scrollY}, shellScroll ${g2.shellScroll}, overflow ${g2.docOverflow}px`);

    // Sender name prefills from the rider; the test-login stub has no phone,
    // so fill it like a first-time rider would.
    await page.type('input[name="sender_phone"]', '8888888888');
    await page.type('input[name="receiver_name"]', 'Test Receiver');
    await page.type('input[name="receiver_phone"]', '9999999999');
    await page.type('input[name="weight"]', '2');
    await page.type('input[name="dimension"]', '30x20x15');

    check('continue visible on step 2', await clickContinue());
    await new Promise((r) => setTimeout(r, 400));
    check('advances to step 3', await wizard(page) === 3);

    const step3 = await page.evaluate(() => {
        const visible = (el) => !!el && el.offsetParent !== null;
        return {
            schedule: visible(document.querySelector('#pickup_date')),
            payment: !!document.querySelector('input[name="payment_method_id"]'),
            submit: [...document.querySelectorAll('button[type="submit"]')]
                .some((b) => b.offsetParent !== null && /Book delivery/.test(b.textContent)),
            recap: [...document.querySelectorAll('.shell-sheet-body span')]
                .some((s) => s.offsetParent !== null && s.textContent.includes('Ahmedabad')),
        };
    });
    check('schedule visible on step 3', step3.schedule);
    check('payment picker present', step3.payment);
    check('submit button shows Book delivery', step3.submit);
    check('recap shows the route', step3.recap);

    const g3 = await geometry(page);
    check('step 3 keeps the shell pinned', g3.scrollY === 0 && g3.shellScroll === 0 && g3.docOverflow <= 1,
        `scrollY ${g3.scrollY}, shellScroll ${g3.shellScroll}, overflow ${g3.docOverflow}px`);

    // Back returns without losing the priced route.
    const backBox = await page.evaluateHandle(() =>
        [...document.querySelectorAll('button')].find((b) => b.offsetParent !== null && /^Back$/.test(b.textContent.trim())));
    const bb = backBox && (await backBox.asElement()?.boundingBox());
    if (bb) await page.mouse.click(bb.x + bb.width / 2, bb.y + bb.height / 2);
    await new Promise((r) => setTimeout(r, 300));
    check('back returns to step 2', await wizard(page) === 2);
    check('route survives navigation', await page.evaluate(
        () => Alpine.$data(document.querySelector('.shell')).options.length) > 0);

    check('no JS errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(failures.length ? `\n${failures.length} check(s) failed` : '\nall checks passed');
    process.exit(failures.length ? 1 : 0);
})();
