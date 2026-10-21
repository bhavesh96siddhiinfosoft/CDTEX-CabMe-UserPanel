<?php

namespace Tests\Feature;

use App\Services\Payments\RazorpayGateway;
use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Razorpay credentials come ONLY from the ADMIN's payment settings — the
 * same single source the mobile apps use. There is no env/config override.
 */
class RazorpayCredentialsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);

        session()->put('cabme_rider', [
            'id' => 42, 'token' => 'tok-123', 'profile' => ['id' => 42],
        ]);
    }

    private function fakeAdmin(array $razorpayRow, array $settings = []): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://admin.test/api/v1/payment-settings/' => Http::response([
                'success' => 'success', 'error' => null, 'message' => 'ok',
                'Razorpay' => [$razorpayRow],
            ]),
            'https://admin.test/api/v1/settings/' => Http::response([
                'success' => 'success',
                'data' => $settings + ['currency' => '$', 'currency_code' => 'USD'],
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);
    }

    public function test_credentials_are_read_from_the_admin_payment_settings(): void
    {
        $this->fakeAdmin([
            'id' => '7', 'isEnabled' => 'true', 'id_payment_method' => '13',
            'libelle' => 'Razorpay', 'key' => 'rzp_test_FROMADMIN', 'secret_key' => 'admin_secret_value',
        ]);

        $gateway = app(RazorpayGateway::class);

        $this->assertTrue($gateway->configured());
        $this->assertSame('rzp_test_FROMADMIN', $gateway->keyId());
    }

    public function test_a_disabled_admin_row_leaves_the_gateway_unconfigured(): void
    {
        $this->fakeAdmin([
            'id' => '7', 'isEnabled' => 'false', 'id_payment_method' => '13',
            'libelle' => 'Razorpay', 'key' => 'rzp_test_X', 'secret_key' => 's',
        ]);

        $this->assertFalse(app(RazorpayGateway::class)->configured());
    }

    public function test_currency_follows_the_admin_active_currency(): void
    {
        $this->fakeAdmin([
            'id' => '7', 'isEnabled' => 'true', 'id_payment_method' => '13',
            'libelle' => 'Razorpay', 'key' => 'rzp_test_A', 'secret_key' => 's',
        ]);

        $this->assertSame('USD', app(RazorpayGateway::class)->currency());
    }

}
