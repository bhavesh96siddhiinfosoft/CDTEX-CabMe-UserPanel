/**
 * Web push end-to-end: grants notification permission, lets the push store
 * auto-subscribe (service worker + FCM getToken + /app/push/subscribe), and
 * prints the resulting state so the harness can confirm a real FCM token
 * reached the admin.
 *
 *   node tests/Browser/push-check.cjs <baseUrl> <riderId> <accessToken>
 */
const puppeteer = require('puppeteer');

const [baseUrl, riderId, token] = process.argv.slice(2);

(async () => {
    const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
    const context = browser.defaultBrowserContext();
    await context.overridePermissions(baseUrl, ['notifications']);

    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await page.goto(`${baseUrl}/__test-login?id=${riderId}&token=${encodeURIComponent(token)}&email=rider@example.com`, { waitUntil: 'networkidle2' });
    await page.goto(`${baseUrl}/app`, { waitUntil: 'networkidle2' });

    const hasConfig = await page.evaluate(() => !!window.__push);
    console.log('push config present:', hasConfig);

    if (hasConfig) {
        // Permission is granted, so init() auto-subscribes; wait until the
        // attempt SETTLES ('enabling' and the initial 'off' are in-flight).
        await page.waitForFunction(
            () => ['ready', 'denied', 'idle', 'unsupported'].includes(Alpine.store('push').state),
            { timeout: 60000 },
        ).catch(() => {});

        console.log('push state:', await page.evaluate(() => Alpine.store('push').state));
        console.log('sw registered:', await page.evaluate(async () => {
            const regs = await navigator.serviceWorker.getRegistrations();
            return regs.map((r) => r.active?.scriptURL?.split('?')[0] ?? 'pending').join(', ') || 'none';
        }));
    }

    console.log('js errors:', errors.length ? errors.join(' | ') : 'none');
    await browser.close();
})();
