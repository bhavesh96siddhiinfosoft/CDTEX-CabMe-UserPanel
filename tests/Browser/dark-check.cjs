/**
 * Dark-theme + header-overlap visual check. Saves screenshots for human
 * review and prints numeric overlap results.
 *
 *   node dark-check.cjs <baseUrl> <riderId> <token> <outDir>
 */
const puppeteer = require('puppeteer');
const path = require('path');

const [baseUrl, riderId, token, outDir] = process.argv.slice(2);

(async () => {
    const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
    const page = await browser.newPage();

    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    // Dark theme before any app JS runs.
    await page.evaluateOnNewDocument(() => {
        try { localStorage.setItem('cabme_theme', 'dark'); } catch {}
    });

    await page.setViewport({ width: 1440, height: 900 });
    await page.goto(`${baseUrl}/__test-login?id=${riderId}&token=${encodeURIComponent(token)}`, { waitUntil: 'networkidle2' });

    // ---- header overlap at several widths (signed in, so avatar too) ----
    for (const width of [1280, 1440, 1680]) {
        await page.setViewport({ width, height: 900 });
        await page.goto(`${baseUrl}/app/ride`, { waitUntil: 'networkidle2' });
        const overlap = await page.evaluate(() => {
            const nav = document.querySelector('header nav');
            const right = document.querySelector('header .ms-auto');
            const logo = document.querySelector('header a[href*="//"], header > div > a');
            if (!nav || !right) return { navVisible: false };
            const n = nav.getBoundingClientRect();
            const r = right.getBoundingClientRect();
            const l = logo ? logo.getBoundingClientRect() : { right: 0 };
            // nav is flex-1; measure its CONTENT (first/last child links)
            const links = nav.querySelectorAll('a');
            const first = links[0]?.getBoundingClientRect();
            const last = links[links.length - 1]?.getBoundingClientRect();
            return {
                navVisible: getComputedStyle(nav).display !== 'none',
                contentOverlapsRight: last ? last.right > r.left + 1 : false,
                contentOverlapsLogo: first ? first.left < l.right - 1 : false,
                gapToRight: last ? Math.round(r.left - last.right) : null,
                gapToLogo: first ? Math.round(first.left - l.right) : null,
            };
        });
        console.log(`header @${width}:`, JSON.stringify(overlap));
    }

    // ---- dark screenshots ----
    await page.setViewport({ width: 1440, height: 900 });

    // Ride booking with fares + payment tiles
    await page.goto(`${baseUrl}/app/ride`, { waitUntil: 'networkidle2' });
    await new Promise((r) => setTimeout(r, 800));
    await page.evaluate(() => {
        const c = Alpine.$data(document.querySelector('.shell'));
        c.setPoint('pickup', 23.0225, 72.5714, 'Ahmedabad');
        c.setPoint('drop', 21.1702, 72.8311, 'Surat');
    });
    await page.waitForFunction(
        () => Alpine.$data(document.querySelector('.shell')).options.length > 0,
        { timeout: 25000 },
    ).catch(() => {});
    await new Promise((r) => setTimeout(r, 1200));
    await page.screenshot({ path: path.join(outDir, 'dark-ride-top.png') });
    // scroll the sheet to the payment section
    await page.evaluate(() => {
        const el = document.querySelector('input[name="payment_method_id"]');
        el?.closest('div')?.scrollIntoView({ block: 'center' });
    });
    await new Promise((r) => setTimeout(r, 400));
    await page.screenshot({ path: path.join(outDir, 'dark-ride-payment.png') });

    // Wallet
    await page.goto(`${baseUrl}/app/wallet`, { waitUntil: 'networkidle2' });
    await new Promise((r) => setTimeout(r, 600));
    await page.screenshot({ path: path.join(outDir, 'dark-wallet.png') });

    // Parcel wizard step 1 (categories) in dark
    await page.goto(`${baseUrl}/app/parcel`, { waitUntil: 'networkidle2' });
    await new Promise((r) => setTimeout(r, 700));
    await page.evaluate(() => {
        const c = Alpine.$data(document.querySelector('.shell'));
        c.setPoint('pickup', 23.0225, 72.5714, 'Ahmedabad');
        c.setPoint('drop', 21.1702, 72.8311, 'Surat');
    });
    await page.waitForFunction(
        () => Alpine.$data(document.querySelector('.shell')).options.length > 0,
        { timeout: 25000 },
    ).catch(() => {});
    await new Promise((r) => setTimeout(r, 900));
    await page.screenshot({ path: path.join(outDir, 'dark-parcel.png') });

    // Guest home in dark: blue route line + header (signed out)
    const guest = await browser.newPage();
    await guest.evaluateOnNewDocument(() => {
        try { localStorage.setItem('cabme_theme', 'dark'); } catch {}
    });
    await guest.setViewport({ width: 1440, height: 900 });
    await guest.goto(`${baseUrl}/`, { waitUntil: 'networkidle2' });
    await new Promise((r) => setTimeout(r, 800));
    await guest.evaluate(() => {
        const root = document.querySelector('[x-data*="placePicker"], .shell') ?? document.body;
        const c = window.Alpine ? Alpine.$data(root) : null;
        if (c?.setPoint) {
            c.setPoint('pickup', 23.0225, 72.5714, 'Ahmedabad');
            c.setPoint('drop', 24.1721, 72.4381, 'Tharad');
        }
    });
    await new Promise((r) => setTimeout(r, 1200));
    await guest.screenshot({ path: path.join(outDir, 'dark-home-route.png') });

    console.log('js errors:', errors.length ? errors.join(' | ') : 'none');
    await browser.close();
})();
