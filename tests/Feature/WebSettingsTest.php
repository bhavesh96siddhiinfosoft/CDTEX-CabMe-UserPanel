<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin's Web Settings screen drives the panel's colour, logos, footer,
 * social links and cookie bar. These assert that plumbing end to end.
 */
class WebSettingsTest extends TestCase
{
    private function fakeSettings(array $overrides = []): void
    {
        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        // Settings are cached for real, and CabmeSettings is a scoped singleton
        // that memoises them, so both have to go for a re-fake to take effect
        // within a single test.
        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);

        Http::preventStrayRequests();
        Http::fake([
            // No ride in progress — otherwise /app/ride redirects to the
            // tracking screen instead of rendering the booking form.
            'https://admin.test/api/v1/user-recent-ride/' => Http::response([
                'success' => 'Failed',
                'message' => 'No data found',
                'data' => null,
            ]),
            'https://admin.test/api/v1/settings/' => Http::response([
                'success' => 'success',
                'data' => array_merge([
                    'title' => 'CabMe',
                    'active_services' => ['ride', 'parcel', 'rental'],
                    'currency' => '$',
                    'decimal_digit' => 2,
                ], $overrides),
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);
    }

    private function signedIn(): self
    {
        return $this->withSession(['cabme_rider' => [
            'id' => 42,
            'token' => 'tok-123',
            'profile' => ['id' => 42, 'prenom' => 'Asha', 'nom' => 'Rao'],
        ]]);
    }

    public function test_the_admin_colour_becomes_the_full_brand_scale(): void
    {
        $this->fakeSettings(['website_color' => '#0fa7c2']);

        $html = $this->get('/')->assertOk()->getContent();

        // The base colour lands on 600, and the derived steps are injected too.
        $this->assertStringContainsString('--color-brand-600:#0fa7c2', $html);
        $this->assertStringContainsString('--color-brand-50:', $html);
        $this->assertStringContainsString('--color-brand-900:', $html);
        $this->assertStringContainsString('--brand-rgb:15, 167, 194', $html);

        // Dark brand colour, so brand surfaces should use white text.
        $this->assertStringContainsString('--brand-contrast:#ffffff', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#0fa7c2">', $html);
    }

    /**
     * The panel and the customer app are themed separately. They used to
     * share `website_color`, so changing one silently restyled the other.
     */
    public function test_the_panel_colour_is_independent_of_the_app_colour(): void
    {
        $this->fakeSettings([
            'web_panel_color' => '#0fa7c2',
            'website_color' => '#e71313',   // the customer app stays red
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('--color-brand-600:#0fa7c2', $html);
        $this->assertStringNotContainsString('#e71313', $html);
    }

    public function test_it_falls_back_to_the_app_colour_on_an_unmigrated_admin(): void
    {
        // No web_panel_color column yet: the panel keeps the look it had.
        $this->fakeSettings(['website_color' => '#0fa7c2']);

        $this->get('/')->assertOk()->assertSee('--color-brand-600:#0fa7c2', false);
    }

    public function test_a_light_brand_colour_flips_the_contrast(): void
    {
        $this->fakeSettings(['website_color' => '#ffd400']);

        $this->get('/')->assertOk()->assertSee('--brand-contrast:#1f2937', false);
    }

    public function test_an_unusable_colour_falls_back_rather_than_breaking_the_page(): void
    {
        $this->fakeSettings(['website_color' => 'not-a-colour']);

        $this->get('/')->assertOk()->assertSee('--color-brand-600:#d92662', false);
    }

    public function test_logos_and_favicon_come_from_the_admin(): void
    {
        $this->fakeSettings([
            'web_header_logo' => 'https://admin.test/assets/images/web_header_logo.png',
            'web_footer_logo' => 'https://admin.test/assets/images/web_footer_logo.png',
            'web_favicon' => 'https://admin.test/assets/images/web_favicon.png',
        ]);

        $this->get('/')->assertOk()
            ->assertSee('web_header_logo.png', false)
            ->assertSee('web_footer_logo.png', false)
            ->assertSee('<link rel="icon" href="https://admin.test/assets/images/web_favicon.png">', false);
    }

    public function test_footer_description_and_social_links_render(): void
    {
        $this->fakeSettings([
            'web_footer_description' => 'Moving the city, one trip at a time.',
            'web_social' => [
                'facebook' => 'https://facebook.com/cabme',
                'youtube' => 'https://youtube.com/@cabme',
                'instagram' => '',   // blank ones must not render
            ],
        ]);

        $response = $this->get('/')->assertOk();

        $response->assertSee('Moving the city, one trip at a time.');
        $response->assertSee('https://facebook.com/cabme', false);
        $response->assertSee('https://youtube.com/@cabme', false);
        $response->assertDontSee('Instagram');
    }

    public function test_the_cookie_bar_is_hidden_when_disabled(): void
    {
        $this->fakeSettings(['web_cookie_consent' => ['enabled' => false, 'message' => 'Hidden bar']]);

        $this->get('/')->assertOk()->assertDontSee('Hidden bar');
    }

    public function test_the_cookie_bar_renders_when_enabled(): void
    {
        $this->fakeSettings([
            'web_cookie_consent' => [
                'enabled' => true,
                'message' => 'We use cookies here.',
                'buttonText' => 'Fine by me',
                'policyUrl' => 'https://cabme.test/cookies',
            ],
        ]);

        $this->get('/')->assertOk()
            ->assertSee('We use cookies here.')
            ->assertSee('Fine by me')
            ->assertSee('https://cabme.test/cookies', false);
    }

    public function test_the_booking_screens_render_in_the_map_first_shell(): void
    {
        $this->fakeSettings();

        foreach (['/app/ride' => 'mapBooking', '/app/parcel' => 'mapBooking', '/app/rental' => 'placePicker'] as $url => $component) {
            $html = $this->signedIn()->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('shell-map', $html, "$url should render the map layer");
            $this->assertStringContainsString('shell-sheet', $html, "$url should render the sheet");
            $this->assertStringContainsString($component, $html, "$url should mount $component");

            // html AND body must both be pinned: focusing a visually-hidden
            // radio otherwise scrolls the document and leaves a blank
            // half-screen below the shell.
            $this->assertStringContainsString('<html lang="en" dir="ltr" class="shell-locked">', $html, "$url should lock the document");
            $this->assertStringContainsString('<body class="shell-frame"', $html, "$url should take the body out of flow");
        }
    }

    public function test_a_disabled_service_is_not_reachable(): void
    {
        $this->fakeSettings(['active_services' => ['ride']]);

        $this->signedIn()->get('/app/parcel')->assertNotFound();
        $this->signedIn()->get('/app/rental')->assertNotFound();
        $this->signedIn()->get('/app/ride')->assertOk();
    }
}
