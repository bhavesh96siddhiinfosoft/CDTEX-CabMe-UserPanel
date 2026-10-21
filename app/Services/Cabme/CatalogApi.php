<?php

namespace App\Services\Cabme;

use App\Support\ApiResult;
use App\Support\PaymentMethods;

/**
 * Reference data shown while composing a booking: vehicle types with fares,
 * parcel categories, rental packages, coupons, banners, payment methods.
 */
class CatalogApi
{
    public function __construct(
        private readonly CabmeClient $client,
        private readonly \App\Support\CabmeSettings $settings,
    ) {
    }

    /**
     * Vehicle types priced for a specific trip. This is the fare quote used
     * across the ride flow — the admin applies zone delivery charges and the
     * surge multiplier for the pickup point inside this endpoint, so there is
     * no separate fare-estimate call to make.
     */
    public function vehicleTypesWithFare(array $trip): ApiResult
    {
        return $this->client->auth('POST', 'v1/vehicle-category-basefare/', [
            'latitude_depart' => $trip['pickup_lat'],
            'longitude_depart' => $trip['pickup_lng'],
            'latitude_arrivee' => $trip['drop_lat'],
            'longitude_arrivee' => $trip['drop_lng'],
            'distance' => $trip['distance'],
        ]);
    }

    /**
     * Unpriced vehicle types — a guest call, so the landing page can show
     * ride options before sign-in the way Uber does. Fares stay behind a
     * rider token.
     */
    public function vehicleTypes(): ApiResult
    {
        return $this->client->guest('GET', 'v1/vehicle-category/');
    }

    public function parcelCategories(): ApiResult
    {
        return $this->client->auth('GET', 'v1/get-parcel-category/');
    }

    /**
     * Parcel categories priced for a trip.
     *
     * Note the parameter names: this endpoint wants lat_source/lng_source,
     * NOT the latitude_depart/longitude_depart that the vehicle fare endpoint
     * above uses. The two are inconsistent in the admin.
     */
    public function parcelCategoriesWithFare(array $trip): ApiResult
    {
        return $this->client->auth('POST', 'v1/parcel-category-basefare/', [
            'lat_source' => $trip['pickup_lat'],
            'lng_source' => $trip['pickup_lng'],
            'lat_destination' => $trip['drop_lat'],
            'lng_destination' => $trip['drop_lng'],
            'distance' => $trip['distance'],
            'parcel_weight' => $trip['weight'] ?? null,
        ]);
    }

    public function rentalPackages(): ApiResult
    {
        return $this->client->auth('POST', 'v1/get-rental-packages/');
    }

    /** Coupons for a service. $type is one of ride, parcel, rental. */
    public function coupons(string $type = 'ride'): ApiResult
    {
        return $this->client->auth('POST', 'v1/discount-list/', ['coupon_type' => $type]);
    }

    public function banners(): ApiResult
    {
        return $this->client->auth('GET', 'v1/get-banners/');
    }

    public function paymentMethods(): ApiResult
    {
        return $this->client->auth('GET', 'v1/payment-settings/');
    }

    /**
     * The payment methods a rider can actually use, in a given context.
     *
     * v1/payment-settings/ does not return a list. It returns one key per
     * gateway at the TOP level of the body — "Strip", "Cash", "My Wallet",
     * "Razorpay", … — each holding rows of gateway config. So there is nothing
     * under `data`, and the payload has to be reshaped here.
     *
     * $context decides what may be offered, mirroring how the admin actually
     * settles each booking type (verified in its controllers):
     *
     *  - 'ride'   Nothing is charged at booking — the RIDER pays from the
     *             Pay Now screen once the trip is on ride (the customer's
     *             complete-requete call settles) — so every method is offered.
     *  - 'parcel' Pay-first, and paid before a driver is assigned — so no
     *             cash: there is nobody to hand it to. Wallet (the ADMIN
     *             debits it at register) plus gateways this panel can
     *             charge through.
     *  - 'rental' Nothing is charged at booking either: the driver's final-km
     *             call fixes the price and the rider pays from the tracking
     *             screen, which settles through rental-complete — wallet
     *             included, so every enabled method is offered.
     *  - 'topup'  Charged gateways only — cash and the wallet itself cannot
     *             fund a top-up, and an unchargeable gateway dead-ends.
     *
     * @return array<int, array{id:int,label:string,logo:?string,kind:string}>
     */
    public function enabledPaymentMethods(string $context = 'ride'): array
    {
        $body = $this->paymentMethods()->raw;

        $methods = [];

        foreach ($body as $key => $group) {
            // Skip the envelope; everything else is a gateway bucket.
            if (in_array($key, ['success', 'error', 'message', 'code', 'data'], true) || ! is_array($group)) {
                continue;
            }

            // A bucket is either a list of rows or a single row.
            foreach (array_is_list($group) ? $group : [$group] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $id = (int) ($row['id_payment_method'] ?? 0);
                $enabled = in_array((string) ($row['isEnabled'] ?? ''), ['true', '1', 'yes'], true);

                if ($id <= 0 || ! $enabled) {
                    continue;
                }

                // The gateway's own key is the friendlier label when the joined
                // libelle is missing (Mercadopago sends neither libelle nor logo).
                $label = $row['libelle'] ?? $key;

                // Retired everywhere: never offered, in any context.
                if (PaymentMethods::isRetired($label)) {
                    continue;
                }

                $offline = PaymentMethods::isOffline($label);
                $internal = PaymentMethods::isInternal($label);
                $chargeable = ! $offline && ! $internal
                    && PaymentMethods::isImplemented($label)
                    && PaymentMethods::isConfigured($row, $label);

                $allowed = match ($context) {
                    'ride' => true,
                    'parcel' => $internal || $chargeable,
                    'rental' => $offline || $internal || $chargeable,
                    'topup' => $chargeable,
                    default => true,
                };

                if (! $allowed) {
                    continue;
                }

                $kind = match (true) {
                    PaymentMethods::isOffline($label) => 'offline',
                    PaymentMethods::isInternal($label) => 'wallet',
                    default => 'gateway',
                };

                // Region-locked gateways charge their own currency; the tile
                // says so when it differs from the displayed one. Paystack is
                // skipped: it charges the merchant account's home currency,
                // which the panel cannot know.
                $chargedIn = null;

                if ($kind === 'gateway' && ($driver = PaymentMethods::driverKey($label)) && $driver !== 'paystack') {
                    $display = strtoupper((string) $this->settings->get('currency_code', ''));
                    $charge = \App\Support\GatewayCurrency::chargeCurrency($driver, $display);
                    $chargedIn = ($display !== '' && $charge !== $display) ? $charge : null;
                }

                $methods[$id] = [
                    'id' => $id,
                    'label' => $label,
                    // The shared square icon set, redrawn onto one canvas so
                    // every tile matches; the admin's upload is the fallback.
                    'logo' => app(\App\Support\PaymentLogos::class)->forMethod($label, $row['image'] ?? null),
                    'kind' => $kind,
                    // Redirect gateways need a popup opened inside the click.
                    'flow' => $kind === 'gateway' ? PaymentMethods::browserFlow($label) : 'none',
                    'charged_in' => $chargedIn,
                ];
            }
        }

        return array_values($methods);
    }

    /** Service-area polygons, used to keep pickup pins inside coverage. */
    public function zones(): ApiResult
    {
        return $this->client->guest('GET', 'v1/zone/');
    }

    /**
     * Interface languages enabled in the admin's language screen. Translation
     * strings live in this panel's lang/ files — the admin only decides which
     * languages are offered and which are right-to-left.
     *
     * An empty result (API down) is not cached, so the switcher reappears on
     * the next successful fetch.
     *
     * @return array<int, array{code:string,name:string,flag:?string,rtl:bool}>
     */
    public function languages(): array
    {
        $cached = \Illuminate\Support\Facades\Cache::get('cabme.languages');

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $rows = $this->client->guest('GET', 'v1/language/')->list();
        } catch (\Throwable) {
            // Locale selection must never take a page down with it.
            return [];
        }

        $languages = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ($row['status'] ?? '') !== 'true') {
                continue;
            }

            $code = strtolower(trim((string) ($row['code'] ?? '')));

            if ($code === '') {
                continue;
            }

            $languages[$code] = [
                'code' => $code,
                'name' => (string) ($row['language'] ?? strtoupper($code)),
                'flag' => $row['flag'] ?? null,
                'rtl' => ($row['is_rtl'] ?? '') === 'true',
            ];
        }

        $languages = array_values($languages);

        if ($languages !== []) {
            \Illuminate\Support\Facades\Cache::put('cabme.languages', $languages, 300);
        }

        return $languages;
    }
}
