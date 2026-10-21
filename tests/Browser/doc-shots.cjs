/**
 * Documentation screenshot capture.
 *
 * Drives the running cabme-admin (:8124) and cabme-web user panel (:8000) and
 * writes one PNG per documented screen into the CabMe Documentation assets
 * folder. Run with:
 *
 *   node tests/Browser/doc-shots.cjs           # everything
 *   node tests/Browser/doc-shots.cjs admin     # admin panel only
 *   node tests/Browser/doc-shots.cjs web       # user panel only
 */
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const ADMIN = process.env.ADMIN_URL || 'http://127.0.0.1:8124';
const WEB = process.env.WEB_URL || 'http://127.0.0.1:8000';
const DISPATCHER = process.env.DISPATCHER_URL || 'http://127.0.0.1:8126';
const EMAIL = process.env.ADMIN_EMAIL || 'admin@cabme.com';
const DISP_EMAIL = process.env.DISPATCHER_EMAIL || 'dispatcher@cabme.com';
const DISP_PASSWORD = process.env.DISPATCHER_PASSWORD || '12345678';
const PASSWORD = process.env.ADMIN_PASSWORD || '12345678';
const OUT = process.env.SHOT_DIR || 'D:/Documentation/Cabme Documentation/assets/images';

const which = (process.argv[2] || 'all').toLowerCase();

// [filename, path, options]
//   full:  capture the entire scrollable page rather than the viewport
//   wait:  extra milliseconds to settle (maps, charts, datatables)
const ADMIN_SHOTS = [
  ['admin_login.png', '/login'],
  ['admin_dashboard.png', '/dashboard', { full: true, wait: 2500 }],
  ['admin_access_roles.png', '/roles'],
  ['admin_users_list.png', '/admin-users'],
  ['admin_zones.png', '/zone', { wait: 1500 }],
  ['admin_zone_create.png', '/zone/create', { wait: 2500 }],
  ['admin_surge_zone.png', '/surge-zone'],
  ['admin_rides.png', '/rides'],
  ['admin_parcel_orders.png', '/parcel'],
  ['admin_rental_orders.png', '/rental-orders'],
  ['admin_customers.png', '/users'],
  ['admin_owners.png', '/owners'],
  ['admin_drivers.png', '/drivers'],
  ['admin_fleet_drivers.png', '/fleet-drivers'],
  ['admin_coupons.png', '/coupons'],
  ['admin_commission.png', '/commission'],
  ['admin_subscription_plans.png', '/subscription-plans'],
  ['admin_vehicle_type.png', '/vehicle-type'],
  ['admin_brand.png', '/brands'],
  ['admin_car_model.png', '/car-model'],
  ['admin_parcel_category.png', '/parcel-category'],
  ['admin_rental_packages.png', '/rental-packages'],
  ['admin_complaints.png', '/complaints'],
  ['admin_sos.png', '/sos'],
  ['admin_notifications.png', '/notification'],
  ['admin_cms.png', '/cms'],
  ['admin_privacy_policy.png', '/privacy-policy'],
  ['admin_payment_methods.png', '/settings/payment/stripe', { full: true }],
  ['admin_payment_razorpay.png', '/settings/payment/razorpay', { full: true }],
  ['admin_payment_cod.png', '/settings/payment/cod'],
  ['admin_settings_documents.png', '/settings/documents'],
  ['admin_settings_referral.png', '/settings/referral'],
  ['admin_settings_version.png', '/settings/version'],
  ['admin_driver_create.png', '/drivers/create', { full: true }],
  ['admin_coupon_create.png', '/coupons/create', { full: true }],
  ['admin_currency.png', '/currency'],
  ['admin_language.png', '/language'],
  ['admin_transactions.png', '/walletstransaction'],
  ['admin_payout_request.png', '/payoutRequest'],
  ['admin_email_templates.png', '/email-template'],
  ['admin_banners.png', '/banners'],
  ['admin_onboarding.png', '/on-boarding'],
  ['admin_reports_user.png', '/reports/userreport'],
  ['admin_reports_driver.png', '/reports/driverreport'],
  ['admin_reports_travel.png', '/reports/travelreport'],
  ['admin_settings.png', '/settings', { full: true }],
  ['admin_settings_branding.png', '/settings/branding', { full: true }],
  ['admin_settings_web.png', '/settings/web', { full: true }],
  ['admin_settings_map.png', '/settings/map'],
  ['admin_settings_ride.png', '/settings/ride'],
  ['admin_settings_surge.png', '/settings/surge'],
  ['admin_settings_services.png', '/settings/services'],
  ['admin_settings_wallet.png', '/settings/wallet'],
  ['admin_settings_notifications.png', '/settings/notifications'],
  ['admin_settings_contact.png', '/settings/contact'],
  ['admin_tax.png', '/tax'],
  ['admin_driver_documents.png', '/driver-document'],
  ['admin_live_map.png', '/map', { wait: 3500 }],
  ['admin_dispatchers.png', '/dispatcher-users'],
  ['admin_subscription_history.png', '/driver/subscription-plan/history'],
  ['admin_reports_owner.png', '/reports/ownerreport'],
  ['admin_parcel_charges.png', '/parcel-charges', { full: true }],
  ['admin_subscription_plan_create.png', '/subscription-plans/create', { full: true }],
];

const WEB_SHOTS = [
  // The home page is a full-bleed map hero — a tall capture just stretches the
  // map, so this one stays at viewport height.
  ['web_home.png', '/', { wait: 2000 }],
  ['web_services.png', '/services', { full: true, wait: 1500 }],
  ['web_login.png', '/login'],
  ['web_register.png', '/register', { full: true }],
  ['web_coverage.png', '/coverage', { full: true, wait: 2000 }],
  ['web_faq.png', '/faq', { full: true }],
  ['web_contact.png', '/contact', { full: true, wait: 1500 }],
];

const DISPATCHER_SHOTS = [
  ['dispatcher_login.png', '/login'],
  ['dispatcher_dashboard.png', '/dashboard', { wait: 3500 }],
  ['dispatcher_rides.png', '/rides'],
  ['dispatcher_ride_book.png', '/ride-book', { full: true, wait: 2500 }],
  ['dispatcher_parcel.png', '/parcel'],
  ['dispatcher_parcel_book.png', '/parcel-book', { full: true, wait: 2500 }],
  ['dispatcher_rental.png', '/rental'],
  ['dispatcher_rental_book.png', '/rental-book', { full: true, wait: 2500 }],
  ['dispatcher_create_user.png', '/create-user', { full: true }],
  ['dispatcher_profile.png', '/users/profile', { full: true }],
];

// Captured after signing a rider in through the dev /__test-login route.
const WEB_RIDER_SHOTS = [
  ['web_app_home.png', '/app', { full: true, wait: 1500 }],
  ['web_ride_booking.png', '/app/ride', { wait: 3000 }],
  ['web_parcel_booking.png', '/app/parcel', { wait: 3000 }],
  ['web_rental_booking.png', '/app/rental', { wait: 2500 }],
  ['web_trips.png', '/app/trips/ride', { full: true, wait: 1500 }],
  ['web_wallet.png', '/app/wallet', { full: true, wait: 1500 }],
  ['web_profile.png', '/app/profile', { full: true, wait: 1200 }],
  ['web_referrals.png', '/app/referrals', { full: true, wait: 1200 }],
  ['web_support.png', '/app/support', { full: true, wait: 1200 }],
];

function log(s) { process.stdout.write(s + '\n'); }

async function settle(page, ms) {
  await new Promise(r => setTimeout(r, ms));
}

const VIEW = { width: 1600, height: 1000 };
// A very tall viewport over a Leaflet map makes the renderer allocate tiles for
// the whole area and can crash it, so this stays modest.
const MAX_TALL = 3000;

/**
 * A plain fullPage screenshot leaves fixed-position chrome (the admin's
 * sidebar) drawn only to the original viewport height, with dead space below
 * it. Growing the viewport to the document height instead makes the sidebar
 * paint the whole way down, so the capture looks like the real screen.
 */
async function shoot(page, base, [file, route, opts = {}]) {
  const url = base + route;
  try {
    await page.setViewport({ ...VIEW, deviceScaleFactor: 1 });
    const res = await page.goto(url, { waitUntil: 'networkidle2', timeout: 45000 });
    await settle(page, opts.wait || 900);
    const status = res ? res.status() : 0;
    const finalUrl = page.url();
    if (status >= 400) { log('  SKIP ' + file + '  (HTTP ' + status + ' at ' + route + ')'); return false; }
    if (/\/login/.test(finalUrl) && !/login/.test(route)) {
      log('  SKIP ' + file + '  (bounced to login — session lost at ' + route + ')');
      return false;
    }

    // The cookie banner covers the bottom of every user-panel page.
    await page.evaluate(() => {
      const btn = [...document.querySelectorAll('button, a')]
        .find(b => /^(got it|accept|ok)$/i.test((b.textContent || '').trim()));
      if (btn) btn.click();
    }).catch(() => {});
    await settle(page, 300);

    if (opts.full) {
      const h = await page.evaluate(() => Math.ceil(Math.max(
        document.body.scrollHeight, document.documentElement.scrollHeight,
        document.body.offsetHeight, document.documentElement.offsetHeight)));
      const tall = Math.min(Math.max(h, VIEW.height), MAX_TALL);
      await page.setViewport({ ...VIEW, height: tall, deviceScaleFactor: 1 });
      await settle(page, 700); // let the sidebar and any sticky bars re-lay-out
    }

    await page.screenshot({ path: path.join(OUT, file) });
    // Shrink straight back so the next navigation never starts on a tall page.
    if (opts.full) await page.setViewport({ ...VIEW, deviceScaleFactor: 1 });
    log('  ok   ' + file + '  <- ' + route + (opts.full ? '  (tall)' : ''));
    return true;
  } catch (e) {
    log('  FAIL ' + file + '  (' + e.message.split('\n')[0] + ')');
    return false;
  }
}

function launch() {
  return puppeteer.launch({
    headless: 'new',
    args: [
      '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu',
      '--window-size=1600,1000',
    ],
    defaultViewport: { width: 1600, height: 1000, deviceScaleFactor: 1 },
  });
}

async function adminSignIn(page) {
  await page.goto(ADMIN + '/login', { waitUntil: 'networkidle2', timeout: 45000 });
  await settle(page, 600);
  await page.type('input[name="email"]', EMAIL, { delay: 10 });
  await page.type('input[name="password"]', PASSWORD, { delay: 10 });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {}),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
  await settle(page, 1500);
  return !/\/login/.test(page.url());
}

async function dispatcherSignIn(page) {
  await page.goto(DISPATCHER + '/login', { waitUntil: 'networkidle2', timeout: 45000 });
  await settle(page, 600);
  await page.type('input[name="email"]', DISP_EMAIL, { delay: 10 });
  await page.type('input[name="password"]', DISP_PASSWORD, { delay: 10 });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {}),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
  await settle(page, 1500);
  return !/\/login/.test(page.url());
}

async function riderSignIn(page) {
  const rid = process.env.RIDER_ID, rtok = process.env.RIDER_TOKEN;
  if (!rid || !rtok) return false;
  const url = WEB + '/__test-login?id=' + encodeURIComponent(rid) + '&token=' + encodeURIComponent(rtok);
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {});
  await settle(page, 1200);
  return !/__test-login|\/login/.test(page.url());
}

/**
 * Runs a shot list, rebuilding the browser and re-authenticating whenever the
 * renderer dies. A tall capture over a map can take the whole browser with it,
 * and losing the rest of the run to that is not worth it.
 */
async function runList(state, list, base, signIn, results) {
  for (const shot of list) {
    for (let attempt = 0; attempt < 2; attempt++) {
      const alive = state.browser && state.browser.connected && state.page && !state.page.isClosed();
      if (!alive) {
        if (state.browser) { try { await state.browser.close(); } catch {} }
        state.browser = await launch();
        state.page = await state.browser.newPage();
        if (signIn && !(await signIn(state.page))) {
          log('  sign-in failed after restart — stopping this section');
          return false;
        }
        if (attempt > 0) log('  (browser restarted)');
      }
      if (await shoot(state.page, base, shot)) { results.ok++; break; }
      if (state.browser && state.browser.connected && state.page && !state.page.isClosed()) {
        results.skipped++; break;           // a real 404/redirect, not a crash
      }
      if (attempt === 1) results.skipped++; // crashed twice, give up on this one
    }
  }
  return true;
}

(async () => {
  if (!fs.existsSync(OUT)) { log('output folder missing: ' + OUT); process.exit(1); }

  const results = { ok: 0, skipped: 0 };

  if (which === 'all' || which === 'admin') {
    log('ADMIN  ' + ADMIN);
    const state = { browser: await launch(), page: null };
    state.page = await state.browser.newPage();

    await state.page.goto(ADMIN + '/login', { waitUntil: 'networkidle2', timeout: 45000 });
    await settle(state.page, 600);
    await state.page.screenshot({ path: path.join(OUT, 'admin_login.png') });
    log('  ok   admin_login.png  <- /login');
    results.ok++;

    if (!(await adminSignIn(state.page))) {
      log('  LOGIN FAILED — still on ' + state.page.url());
      log('  set ADMIN_EMAIL / ADMIN_PASSWORD env vars and retry');
      await state.browser.close();
      process.exit(2);
    }
    log('  signed in, now at ' + state.page.url());

    await runList(state, ADMIN_SHOTS.filter(s => s[0] !== 'admin_login.png'), ADMIN, adminSignIn, results);
    try { await state.browser.close(); } catch {}
  }

  if (which === 'all' || which === 'dispatcher') {
    log('DISPATCHER  ' + DISPATCHER);
    const state = { browser: await launch(), page: null };
    state.page = await state.browser.newPage();
    await state.page.goto(DISPATCHER + '/login', { waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {});
    await settle(state.page, 600);
    await state.page.screenshot({ path: path.join(OUT, 'dispatcher_login.png') });
    log('  ok   dispatcher_login.png  <- /login');
    results.ok++;
    if (await dispatcherSignIn(state.page)) {
      log('  signed in, now at ' + state.page.url());
      await runList(state, DISPATCHER_SHOTS.filter(s => s[0] !== 'dispatcher_login.png'), DISPATCHER, dispatcherSignIn, results);
    } else {
      log('  DISPATCHER LOGIN FAILED — set DISPATCHER_EMAIL / DISPATCHER_PASSWORD');
    }
    try { await state.browser.close(); } catch {}
  }

  if (which === 'all' || which === 'web') {
    log('USER PANEL  ' + WEB);
    const state = { browser: await launch(), page: null };
    state.page = await state.browser.newPage();

    await runList(state, WEB_SHOTS, WEB, null, results);

    // Signed-in screens. RIDER_ID / RIDER_TOKEN come from the admin's
    // users_access table; without them the booking screens are skipped.
    if (process.env.RIDER_ID && process.env.RIDER_TOKEN) {
      if (!state.browser.connected || state.page.isClosed()) {
        try { await state.browser.close(); } catch {}
        state.browser = await launch();
        state.page = await state.browser.newPage();
      }
      if (await riderSignIn(state.page)) {
        log('  rider signed in, now at ' + state.page.url());
        await runList(state, WEB_RIDER_SHOTS, WEB, riderSignIn, results);
      } else {
        log('  rider sign-in did not take — now at ' + state.page.url());
      }
    } else {
      log('  (set RIDER_ID and RIDER_TOKEN to also capture the signed-in screens)');
    }
    try { await state.browser.close(); } catch {}
  }

  log('');
  log('captured ' + results.ok + ', skipped/failed ' + results.skipped);
})();
