<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin can take the customer side down from Settings. The flag travels
 * with v1/settings/ as the string "true"/"false", and there is one per
 * audience — this panel must read only the customer one.
 */
class MaintenanceModeTest extends TestCase
{
    private function fakeAdmin(mixed $customerFlag, array $extraSettings = []): void
    {
        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);

        Http::preventStrayRequests();
        Http::fake([
            'https://admin.test/api/v1/settings/' => Http::response([
                'success' => 'success',
                'data' => array_merge([
                    'title' => 'CabMe',
                    'currency' => '$',
                    'active_services' => ['ride', 'parcel', 'rental'],
                    'is_customer_maintenance_mode' => $customerFlag,
                    'is_driver_maintenance_mode' => 'false',
                    'is_dispatcher_maintenance_mode' => 'false',
                ], $extraSettings),
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);
    }

    public function test_the_site_serves_normally_when_the_flag_is_off(): void
    {
        $this->fakeAdmin('false');

        $this->get('/')->assertOk()->assertDontSee('We will be back shortly');
    }

    public function test_the_maintenance_page_replaces_the_site_when_the_flag_is_on(): void
    {
        $this->fakeAdmin('true');

        $response = $this->get('/');

        $response->assertStatus(503)
            ->assertSee('We will be back shortly')
            ->assertHeader('Retry-After', 3600);
    }

    public function test_it_covers_signed_in_pages_too(): void
    {
        $this->fakeAdmin('true');

        session()->put('cabme_rider', [
            'id' => 42, 'token' => 'tok-123',
            'profile' => ['id' => 42, 'email' => 'a@b.test', 'amount' => '10.00'],
        ]);

        $this->get('/app')->assertStatus(503)->assertSee('We will be back shortly');
    }

    public function test_gateway_webhooks_still_get_through(): void
    {
        $this->fakeAdmin('true');

        // Money already in flight must not be refused. The webhook itself may
        // reject the payload, but it must not answer with the notice page.
        $this->post('/webhooks/payfast', [])->assertDontSee('We will be back shortly');
    }

    public function test_only_the_customer_flag_takes_this_panel_down(): void
    {
        $this->fakeAdmin('false', [
            'is_driver_maintenance_mode' => 'true',
            'is_dispatcher_maintenance_mode' => 'true',
        ]);

        $this->get('/')->assertOk();
    }

    public function test_an_unreachable_admin_does_not_take_the_site_down(): void
    {
        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 500)]);

        // Failing closed here would mean one API blip blanks the whole site.
        $this->get('/')->assertOk();
    }
}
