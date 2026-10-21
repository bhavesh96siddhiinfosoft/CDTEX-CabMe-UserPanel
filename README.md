# CabMe Web — rider panel

The customer-facing web panel for CabMe. Riders can book cabs, send parcels and
rent vehicles, track a trip live, manage a wallet, and raise support requests.

Built on **Laravel 12 / PHP 8.2**, with Blade + Tailwind 4 + Alpine + Vite.

---

## Architecture

**This panel owns no database.** `cabme-admin` is the single source of truth;
everything here goes over its REST API at `{CABME_API_URL}/api/v1/*` — the same
API the iOS/Android apps use. In effect this is the mobile app, on the web.

That is a deliberate departure from `cabme-dispatcher`, which shares the admin's
MySQL database through 25 mirrored Eloquent models and re-implements fare logic
locally. Going through the API means pricing, zone checks, driver assignment and
commission stay in exactly one place.

```
Browser ──► cabme-web (session, Blade UI) ──► cabme-admin /api/v1/* ──► MySQL
```

### The pieces

| Path | Role |
|---|---|
| `app/Services/Cabme/CabmeClient.php` | The only outbound door. Attaches auth headers, retries transport failures, normalises replies. |
| `app/Services/Cabme/*Api.php` | Endpoint groups — `AuthApi`, `RideApi`, `ParcelApi`, `RentalApi`, `BookingApi`, `WalletApi`, `CatalogApi`, `SupportApi`. |
| `app/Support/ApiResult.php` | One predictable result shape (see below). |
| `app/Support/Rider.php` | The signed-in rider, held in the session. There is no `users` table. |
| `app/Support/CabmeSettings.php` | Cached `v1/settings/` — currency, map provider, active services. |
| `app/Services/GeoService.php` | Road distance/duration via Google Directions or OSRM. |
| `app/Http/Middleware/EnsureRider.php` | Guards `/app/*`. |

### Authentication

The admin uses two custom middleware rather than Sanctum:

- **Guest endpoints** (`apiKeyAuth`) need an `apikey` header equal to the
  admin's `APP_KEY`.
- **Rider endpoints** (`accessKeyAuth`) need `apikey` **and** an `accesstoken`,
  which the admin looks up in its `users_access` table.

Signing in exchanges email + password for that `accesstoken`; it and a profile
snapshot are kept in the session. When the admin returns 401 — it deletes the
token row on logout and account deletion — `CabmeClient` clears the session so
the rider is redirected to sign in rather than looping on a dead token.

### The response normaliser

`cabme-admin` reports outcomes in at least four different envelopes, and
**several endpoints report failure with HTTP 200**, so the status code alone can
never be trusted. `ApiResult` collapses all of them into `ok` / `data` /
`message` / `status`:

```php
['success' => 'Success', 'message' => …, 'data' => …]              // 1
['success' => 'Failed',  'error'   => …, 'data' => null]           // 2  ← HTTP 200
['status'  => 1, 'message' => …, 'errors' => [], 'data' => …]      // 3
['response_time' => …, 'data' => ['status' => 401, 'error' => …]]  // 4  ← HTTP 401
```

`'success'` casing varies per controller (`Success` / `success` / `Failed`), so
it is compared case-insensitively. `tests/Unit/ApiResultTest.php` locks all four
shapes in, including a 401 body captured verbatim from a running admin.

---

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Then set the API connection in `.env`:

```dotenv
CABME_API_URL=http://localhost/cabme-admin/public
CABME_API_KEY=base64:…
```

> **`CABME_API_KEY` must equal the admin's `APP_KEY` byte for byte**, including
> the `base64:` prefix — the admin compares them verbatim. A mismatch shows up
> as a bare 401 on every call. This is the most common setup mistake.

Verify the connection before anything else:

```bash
php artisan cabme:check
```

It reports the resolved app name, currency, distance unit, map provider and
active services, and names the specific failure if it cannot connect.

Then run it:

```bash
composer run dev     # server + queue + logs + vite
# or
php artisan serve & npm run dev
```

No `php artisan migrate` — there is no database. Sessions and cache use the
file driver.

---

## Routes

| Area | Routes |
|---|---|
| Public | `/`, `/services`, `/coverage`, `/faq`, `/privacy`, `/terms`, `/contact` |
| Auth | `/login`, `/register`, `/forgot-password`, `/reset-password` |
| Ride | `/app/ride`, `/app/ride/active`, `/app/ride/quote`, `/app/ride/status` |
| Parcel | `/app/parcel`, `/app/parcel/quote` |
| Rental | `/app/rental` |
| History | `/app/trips/{ride\|parcel\|rental}`, `/app/trips/{type}/{id}` |
| Account | `/app/wallet`, `/app/profile`, `/app/referrals`, `/app/support` |

---

## Notes and known gaps

**Live tracking is polling, not push.** `cabme-admin` has
`beyondcode/laravel-websockets` installed but registers no ride channels — its
`channels.php` only has the stock user channel — so there is nothing to
subscribe to. `/app/ride/status` polls `v1/user-recent-ride/` every
`CABME_POLL_INTERVAL` ms (default 5000) and pauses on a backgrounded tab. If
broadcast events are added to the admin later, only
`resources/js/components/ride-tracker.js` needs to change.

**Payments are not wired up.** This is the main thing left before going live.
The admin exposes gateway endpoints (`v1/payments/*` for Stripe, Razorpay,
Paytm, PayPal/Braintree), but this panel does not yet run a checkout. As a
result:

- `WalletController::topup()` currently trusts the amount and transaction id
  posted by the browser. **Do not expose this route publicly as-is** — a rider
  could credit themselves an arbitrary balance. Put a server-side gateway
  verification in front of it first.
- Parcel and rental bookings mint a local `WEB-…` transaction id, because the
  admin requires `transaction_id` at creation time. Replace it with the verified
  gateway reference once checkout exists.

`goride-web`'s `PaymentController` and `Concerns/InteractsWithPaymentGateways`
are a good model for the verify-server-side pattern.

**Fares are quoted by the admin, not computed here.**
`v1/vehicle-category-basefare/` applies zone delivery charges *and* the surge
multiplier for the pickup point, so there is no separate fare-estimate call to
make. (There is no `v1/fare-estimate/` endpoint on either `master` or `dev` —
the two branches are currently identical.) `GeoService` only supplies distance
and duration.

**Geocoding uses Nominatim**, which is rate-limited and asks for at most one
request per second. Routing falls back to the public OSRM demo server. Point
`OSRM_URL` at your own instance and switch to Google (the admin already stores a
key in settings) before real traffic.

**Service availability fails open.** If `v1/settings/` cannot be fetched at all,
all three services are advertised rather than none — otherwise a misconfigured
API key renders a site with no services and no explanation. The admin re-checks
on every booking, so this only affects what the nav offers.

**Not yet built:** social login (`login_type` google/apple is accepted by the
admin's register endpoint), scheduled/future ride booking, multi-stop rides
(the API accepts a `stops` array), in-trip chat, and push notifications.

---

## Tests

```bash
php artisan test
```

Covers the response normaliser against all four admin envelope shapes, and the
sign-in path end to end with a faked API — including that a dead access token is
cleared rather than retried.

---

## Web Settings (cabme-admin)

Branding for this panel is configured in the admin at **Settings → Web Settings**
(`/settings/web`), modelled on the same screen in quicklist-admin:

| Group | Fields |
|---|---|
| Look and feel | Brand colour, panel URL, footer description, Google Map embed |
| Logos and icons | Header logo, footer logo, favicon |
| Social media | Facebook, Instagram, X, LinkedIn, Pinterest, YouTube |
| Cookie consent | Enable, message, button text, policy link |

These ride along on `v1/settings/`, which this panel already caches, so there is
no extra request. Run the migration in cabme-admin before using the screen:

```bash
php artisan migrate   # 2026_09_15_000001_add_web_settings_to_settings
```

**The brand colour moved out of Branding into Web Settings** so it has exactly
one home. `website_color` is the same column as before — existing values carry
over untouched.

### Dynamic theming

The admin picks one colour; `App\Support\Palette` derives the whole 50–900 scale
from it, plus a contrast colour chosen by perceived brightness so text stays
readable on light brand picks. The result is injected as `:root` custom
properties in `partials/head.blade.php`.

Tailwind 4 compiles `bg-brand-600` down to `var(--color-brand-600)`, so
overriding those properties at runtime re-themes every utility — no rebuild, no
per-tenant CSS. The values compiled into `app.css` are only the fallback for
when the API is unreachable.

### Pointing at the admin

`CABME_API_URL` must reach a **running** cabme-admin. If it can't, the panel
silently falls back to its built-in palette (pink) and shows none of your Web
Settings — `php artisan cabme:check` is the fastest way to confirm.

The admin is not served over port 80 on this machine, so the simplest local
setup is to run it on its own port:

```bash
cd ../cabme-admin && php artisan serve --port=8124
```

```dotenv
CABME_API_URL=http://127.0.0.1:8124
```

Point this at your vhost instead once you have one. Note the admin's database is
`cabme_admin` — if it reports `Unknown database 'cabme'`, it is reading a stale
cached config; `php artisan config:clear` in cabme-admin fixes it.

---

## Deploying to shared hosting (Hostinger etc.)

Shared hosting cannot run `npm build`, so **`public/build/` is committed
deliberately** — always run `npm run build` locally before uploading, and make
sure the whole `public/build/` folder (with `manifest.json`) reaches the
server. A missing manifest is exactly the `ViteManifestNotFoundException` 500.

Live `.env` — the values that MUST differ from local:

```dotenv
APP_ENV=production        # also disables the /__test-login dev route
APP_DEBUG=false           # never expose debug pages publicly
APP_URL=https://cabmeweb.yourdomain.com

CABME_API_URL=https://your-live-admin-domain.com   # NOT 127.0.0.1
CABME_API_KEY=<the LIVE admin's APP_KEY, verbatim>
```

After changing `.env` on the server:

```bash
php artisan config:clear && php artisan view:clear && php artisan cabme:check
```

(No SSH? Delete `bootstrap/cache/config.php` and the files in
`storage/framework/views/` via the file manager instead.)

Checklist:
- `storage/` and `bootstrap/cache/` writable by PHP
- document root points at `public/` (or the host's subdomain root maps to it)
- the live admin is reachable FROM the web server (`cabme:check` proves it)
- OSRM: self-host or set the Google key in admin settings — the demo router
  is rate-limited and unfit for production traffic
