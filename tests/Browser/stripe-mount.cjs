const p = require('puppeteer');
const [base, rid, tok] = process.argv.slice(2);
(async () => {
  const b = await p.launch({ headless: 'new', args: ['--no-sandbox'] });
  const pg = await b.newPage(); await pg.setViewport({ width: 1440, height: 900 });
  const errs = []; pg.on('pageerror', e => errs.push(e.message.split('\n')[0]));
  await pg.goto(`${base}/__test-login?id=${rid}&token=${tok}`, { waitUntil: 'networkidle2' });
  await pg.goto(`${base}/app/wallet`, { waitUntil: 'networkidle2' });
  await new Promise(r => setTimeout(r, 900));
  console.log('url:', pg.url());
  const tiles = await pg.evaluate(() => [...document.querySelectorAll('input[name=payment_method_id]')].map(i => i.closest('label').innerText.trim()));
  console.log('tiles:', JSON.stringify(tiles));
  await pg.type('#amount', '50');
  await pg.evaluate(() => {
    const input = [...document.querySelectorAll('input[name=payment_method_id]')]
      .find(i => i.closest('label').innerText.includes('Stripe'));
    input.closest('label').querySelector('span').click();
  });
  await new Promise(r => setTimeout(r, 300));
  await pg.evaluate(() => {
    [...document.querySelectorAll('button')].find(x => x.textContent.includes('Top up') && !x.disabled).click();
  });
  await pg.waitForSelector('[data-stripe-mount] iframe', { timeout: 20000 }).catch(() => {});
  await new Promise(r => setTimeout(r, 2500));
  const d = await pg.evaluate(() => ({
    modal: !!document.querySelector('[data-stripe-mount]'),
    iframes: document.querySelectorAll('[data-stripe-mount] iframe').length,
    iframeH: Math.round(document.querySelector('[data-stripe-mount] iframe')?.getBoundingClientRect().height ?? 0),
    error: document.querySelector('[data-stripe-error]')?.textContent?.trim() || null,
  }));
  console.log(JSON.stringify(d));
  console.log('js errors:', errs.length ? errs.slice(0, 2) : 'none');
  await b.close();
})();
