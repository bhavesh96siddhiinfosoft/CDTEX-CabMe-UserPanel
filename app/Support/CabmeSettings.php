<?php

namespace App\Support;

use App\Services\Cabme\CabmeClient;
use Illuminate\Support\Facades\Cache;

/**
 * Cached view of v1/settings/ — currency, map provider, and which services
 * the admin has switched on. Read on nearly every request, changes rarely.
 */
class CabmeSettings
{
    private const CACHE_KEY = 'cabme.settings';

    private ?array $settings = null;

    private ?Palette $paletteCache = null;

    public function __construct(private readonly CabmeClient $client)
    {
    }

    public function all(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $this->settings = $cached;
        }

        $result = $this->client->guest('GET', 'v1/settings/');
        $settings = $result->ok && is_array($result->data) ? $result->data : [];

        // A success keeps for the full TTL. A failure is kept only briefly —
        // long enough to stop an outage hammering the admin on every render,
        // short enough that fixing CABME_API_URL takes effect on the next
        // reload instead of after five minutes of fallback branding.
        Cache::put(self::CACHE_KEY, $settings, $settings === [] ? 10 : (int) config('cabme.settings_ttl'));

        return $this->settings = $settings;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->all(), $key, $default);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->paletteCache = null;
        $this->settings = null;
    }

    /* ---------------------------------------------------------------- money */

    public function currency(): string
    {
        return (string) $this->get('currency', '$');
    }

    /**
     * The admin stores this as the STRING 'true'/'false' (from the currency
     * row), older builds as 'yes'/'no'. A strict list of allowed values
     * matched neither, so a right-hand symbol was always drawn on the left.
     */
    public function symbolAtRight(): bool
    {
        return filter_var($this->get('symbol_at_right'), FILTER_VALIDATE_BOOLEAN)
            || in_array(strtolower((string) $this->get('symbol_at_right')), ['yes'], true);
    }

    public function decimals(): int
    {
        return (int) $this->get('decimal_digit', 2);
    }

    /** Format an amount the way the admin's currency row says to. */
    public function money(float|int|string|null $amount): string
    {
        $value = number_format((float) $amount, $this->decimals());

        return $this->symbolAtRight()
            ? $value.$this->currency()
            : $this->currency().$value;
    }

    /* ----------------------------------------------------------------- maps */

    public function usesGoogleMaps(): bool
    {
        return $this->get('map_for_application') === 'Google' && $this->googleMapKey() !== '';
    }

    public function googleMapKey(): string
    {
        return (string) $this->get('google_map_api_key', '');
    }

    public function distanceUnit(): string
    {
        return (string) $this->get('delivery_distance', 'km');
    }

    /* ------------------------------------------------------------- services */

    /** False when v1/settings/ could not be fetched at all. */
    public function loaded(): bool
    {
        return $this->all() !== [];
    }

    /**
     * Which of ride / parcel / rental the admin has enabled. The API returns
     * this already exploded into an array, but older admin builds send the raw
     * comma-separated string, so handle both.
     *
     * If settings could not be fetched we fail open and advertise everything:
     * a misconfigured API key would otherwise render a site with no services
     * at all and no hint as to why. The admin re-checks on every booking, so
     * showing a service we cannot confirm costs nothing but a clear error.
     */
    public function activeServices(): array
    {
        if (! $this->loaded()) {
            return ['ride', 'parcel', 'rental'];
        }

        $services = $this->get('active_services', []);

        if (is_string($services)) {
            $services = explode(',', $services);
        }

        return array_values(array_filter(array_map('trim', (array) $services)));
    }

    public function serviceEnabled(string $service): bool
    {
        return in_array($service, $this->activeServices(), true);
    }

    /**
     * The admin has taken the customer side down for maintenance.
     *
     * Stored as the string "true"/"false", and there is one flag per
     * audience — customer, driver, dispatcher — so this panel reads only
     * its own. Fails OPEN: if settings cannot be fetched the site stays up
     * rather than showing a maintenance page nobody switched on.
     */
    public function underMaintenance(): bool
    {
        return filter_var($this->get("is_customer_maintenance_mode", false), FILTER_VALIDATE_BOOLEAN);
    }

    public function appName(): string
    {
        return (string) $this->get('title', $this->get('app_name', config('app.name')));
    }

    /* ------------------------------------------------- web panel branding */

    /**
     * The brand colour chosen in the admin's Web Settings screen. Everything
     * else in the palette is derived from it.
     */
    public function brandColor(): string
    {
        return $this->palette()->base();
    }

    public function palette(): Palette
    {
        // The panel has its own colour now. `website_color` is the customer
        // APP's and is only the fallback, for an admin that has not been
        // migrated yet or has never set the panel colour.
        return $this->paletteCache ??= Palette::fromHex(
            $this->get('web_panel_color') ?: $this->get('website_color')
        );
    }

    public function headerLogo(): ?string
    {
        return $this->get('web_header_logo') ?: null;
    }

    public function footerLogo(): ?string
    {
        // Fall back to the header logo so a half-configured admin still looks right.
        return $this->get('web_footer_logo') ?: $this->headerLogo();
    }

    public function favicon(): ?string
    {
        return $this->get('web_favicon') ?: null;
    }

    public function panelUrl(): ?string
    {
        return $this->get('web_panel_url') ?: null;
    }

    public function footerDescription(): ?string
    {
        return $this->get('web_footer_description') ?: null;
    }

    public function mapIframe(): ?string
    {
        return $this->get('web_map_iframe') ?: null;
    }

    /** Configured social links only, in display order. @return array<string,string> */
    public function socialLinks(): array
    {
        $social = (array) $this->get('web_social', []);

        $ordered = [];
        foreach (['facebook', 'instagram', 'x', 'linkedin', 'pinterest', 'youtube'] as $network) {
            $url = trim((string) ($social[$network] ?? ''));
            if ($url !== '') {
                $ordered[$network] = $url;
            }
        }

        return $ordered;
    }

    /** @return array{enabled:bool,message:string,buttonText:string,policyUrl:string}|null */
    public function cookieConsent(): ?array
    {
        $cookie = (array) $this->get('web_cookie_consent', []);

        if (empty($cookie['enabled'])) {
            return null;
        }

        return [
            'enabled' => true,
            'message' => trim((string) ($cookie['message'] ?? '')) ?: 'We use cookies to improve your experience.',
            'buttonText' => trim((string) ($cookie['buttonText'] ?? '')) ?: 'Got it',
            'policyUrl' => trim((string) ($cookie['policyUrl'] ?? '')),
        ];
    }
}
