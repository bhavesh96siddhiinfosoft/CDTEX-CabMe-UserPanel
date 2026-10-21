<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Paystack, Flutterwave and PayPal follow the same rules as Razorpay and
 * Stripe: the server decides the amount, credentials come only from the
 * admin's payment settings, and nothing the browser reports is trusted —
 * every verify retrieves the charge from the gateway and checks status,
 * ownership (our reference/order) and amount.
 */
class GatewayDriversTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);

        session()->put('cabme_rider', [
            'id' => 42, 'token' => 'tok-123',
            'profile' => ['id' => 42, 'email' => 'a@b.test', 'prenom' => 'Test', 'nom' => 'Rider'],
        ]);
    }

    /** Admin payment-settings with all three gateways credentialed. */
    private function adminSettings(): array
    {
        return [
            'success' => 'success', 'error' => null, 'message' => 'ok',
            'PayStack' => [['id' => '5', 'isEnabled' => 'true', 'id_payment_method' => '11',
                'libelle' => 'PayStack', 'secret_key' => 'sk_test_ps', 'public_key' => 'pk_test_ps']],
            'FlutterWave' => [['id' => '6', 'isEnabled' => 'true', 'id_payment_method' => '12',
                'libelle' => 'FlutterWave', 'secret_key' => 'FLWSECK_TEST-x', 'public_key' => 'FLWPUBK_TEST-x']],
            'PayPal' => [['id' => '10', 'isEnabled' => 'true', 'isLive' => 'false', 'id_payment_method' => '15',
                'libelle' => 'PayPal', 'app_id' => str_repeat('A', 30), 'secret_key' => str_repeat('S', 30)]],
        ];
    }

    private function fake(array $gatewayResponses = []): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge([
            'https://admin.test/api/v1/payment-settings/' => Http::response($this->adminSettings()),
            'https://admin.test/api/v1/settings/' => Http::response([
                'success' => 'success', 'data' => ['currency_code' => 'USD'],
            ]),
        ], $gatewayResponses, [
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]));
    }

    private function pending(string $gateway, array $extra): array
    {
        return ['booking_payment_pending' => array_merge([
            'gateway' => $gateway,
            'amount' => 250.0,
            'method_id' => 11,
            'context' => 'parcel',
        ], $extra)];
    }

    /* ------------------------------------------------------------ Paystack */

    public function test_paystack_order_initializes_a_hosted_checkout(): void
    {
        // Server-side initialize with NO currency — the merchant's own
        // applies; the browser just opens the hosted page in the popup.
        $this->fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123', 'access_code' => 'abc123'],
            ]),
        ]);

        $this->postJson('/app/pay/order', [
            'amount' => 250, 'payment_method_id' => 11, 'context' => 'parcel',
        ])->assertOk()->assertJson([
            'gateway' => 'paystack', 'flow' => 'redirect', 'url' => 'https://checkout.paystack.com/abc123',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'transaction/initialize')
                && $request['amount'] === 25000
                && ! array_key_exists('currency', $request->data());
        });

        $this->assertSame('paystack', session('booking_payment_pending.gateway'));
        $this->assertSame(25000, session('booking_payment_pending.minor'));
        $this->assertNotEmpty(session('booking_payment_pending.reference'));
    }

    public function test_a_successful_paystack_transaction_verifies(): void
    {
        $this->fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['id' => 987654, 'status' => 'success', 'amount' => 25000],
            ]),
        ]);

        $this->withSession($this->pending('paystack', ['reference' => 'parcel_42_ref', 'minor' => 25000]))
            ->postJson('/app/pay/verify', [])
            ->assertOk();

        $this->assertSame('987654', session('booking_payment_verified.transaction_id'));
    }

    public function test_paystack_verify_uses_only_the_pending_reference(): void
    {
        // Whatever reference the browser posts is IGNORED — the lookup runs
        // on the reference the server created at initialize time.
        $this->fake([
            'https://api.paystack.co/transaction/verify/parcel_42_ref' => Http::response([
                'status' => true,
                'data' => ['id' => 987654, 'status' => 'success', 'amount' => 25000],
            ]),
        ]);

        $this->withSession($this->pending('paystack', ['reference' => 'parcel_42_ref', 'minor' => 25000]))
            ->postJson('/app/pay/verify', ['reference' => 'their_cheaper_ref'])
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'transaction/verify/parcel_42_ref'));
    }

    public function test_a_paystack_amount_mismatch_is_rejected(): void
    {
        $this->fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['id' => 987654, 'status' => 'success', 'amount' => 100],
            ]),
        ]);

        $this->withSession($this->pending('paystack', ['reference' => 'parcel_42_ref', 'minor' => 25000]))
            ->postJson('/app/pay/verify', [])
            ->assertStatus(422);
    }

    /* --------------------------------------------------------- Flutterwave */

    public function test_flutterwave_order_returns_the_inline_payload(): void
    {
        $this->fake();

        $this->postJson('/app/pay/order', [
            'amount' => 250, 'payment_method_id' => 12, 'context' => 'parcel',
        ])->assertOk()->assertJson([
            'gateway' => 'flutterwave', 'public_key' => 'FLWPUBK_TEST-x', 'amount' => 250, 'currency' => 'NGN',
        ]);

        $this->assertSame('flutterwave', session('booking_payment_pending.gateway'));
    }

    public function test_a_successful_flutterwave_transaction_verifies(): void
    {
        $this->fake([
            'https://api.flutterwave.com/v3/transactions/555/verify' => Http::response([
                'status' => 'success',
                'data' => ['id' => 555, 'tx_ref' => 'parcel_42_ref', 'status' => 'successful',
                    'amount' => 250, 'currency' => 'NGN'],
            ]),
        ]);

        $this->withSession($this->pending('flutterwave', ['tx_ref' => 'parcel_42_ref', 'expected' => 250.0]))
            ->postJson('/app/pay/verify', ['transaction_id' => '555'])
            ->assertOk();

        $this->assertSame('555', session('booking_payment_verified.transaction_id'));
    }

    public function test_a_flutterwave_transaction_for_another_reference_is_rejected(): void
    {
        // The rider paid $1 on a transaction of their own and posts its id.
        $this->fake([
            'https://api.flutterwave.com/v3/transactions/556/verify' => Http::response([
                'status' => 'success',
                'data' => ['id' => 556, 'tx_ref' => 'their_own_ref', 'status' => 'successful',
                    'amount' => 250, 'currency' => 'NGN'],
            ]),
        ]);

        $this->withSession($this->pending('flutterwave', ['tx_ref' => 'parcel_42_ref', 'expected' => 250.0]))
            ->postJson('/app/pay/verify', ['transaction_id' => '556'])
            ->assertStatus(422);
    }

    public function test_a_flutterwave_underpayment_is_rejected(): void
    {
        $this->fake([
            'https://api.flutterwave.com/v3/transactions/557/verify' => Http::response([
                'status' => 'success',
                'data' => ['id' => 557, 'tx_ref' => 'parcel_42_ref', 'status' => 'successful',
                    'amount' => 1, 'currency' => 'NGN'],
            ]),
        ]);

        $this->withSession($this->pending('flutterwave', ['tx_ref' => 'parcel_42_ref', 'expected' => 250.0]))
            ->postJson('/app/pay/verify', ['transaction_id' => '557'])
            ->assertStatus(422);
    }

    /* -------------------------------------------------------------- PayPal */

    public function test_paypal_order_is_created_server_side(): void
    {
        $this->fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'pp-token', 'expires_in' => 3600,
            ]),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PP_ORDER_1', 'status' => 'CREATED',
            ], 201),
        ]);

        $this->postJson('/app/pay/order', [
            'amount' => 250, 'payment_method_id' => 15, 'context' => 'parcel',
        ])->assertOk()->assertJson([
            'gateway' => 'paypal', 'order_id' => 'PP_ORDER_1', 'amount' => '250.00', 'currency' => 'USD',
        ]);

        $this->assertSame('PP_ORDER_1', session('booking_payment_pending.order_id'));
        $this->assertSame('250.00', session('booking_payment_pending.value'));
    }

    public function test_a_completed_paypal_capture_verifies(): void
    {
        $this->fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'pp-token', 'expires_in' => 3600,
            ]),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/PP_ORDER_1/capture' => Http::response([
                'id' => 'PP_ORDER_1', 'status' => 'COMPLETED',
                'purchase_units' => [[
                    'payments' => ['captures' => [[
                        'id' => 'CAP123', 'amount' => ['currency_code' => 'USD', 'value' => '250.00'],
                    ]]],
                ]],
            ], 201),
        ]);

        $this->withSession($this->pending('paypal', ['order_id' => 'PP_ORDER_1', 'value' => '250.00']))
            ->postJson('/app/pay/verify', ['order_id' => 'PP_ORDER_1'])
            ->assertOk();

        $this->assertSame('CAP123', session('booking_payment_verified.transaction_id'));
    }

    public function test_a_substituted_paypal_order_id_is_rejected_without_a_gateway_call(): void
    {
        $this->fake();

        $this->withSession($this->pending('paypal', ['order_id' => 'PP_ORDER_1', 'value' => '250.00']))
            ->postJson('/app/pay/verify', ['order_id' => 'PP_ORDER_THEIRS'])
            ->assertStatus(422);
    }

    public function test_a_paypal_capture_for_the_wrong_amount_is_rejected(): void
    {
        $this->fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'pp-token', 'expires_in' => 3600,
            ]),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/PP_ORDER_1/capture' => Http::response([
                'id' => 'PP_ORDER_1', 'status' => 'COMPLETED',
                'purchase_units' => [[
                    'payments' => ['captures' => [[
                        'id' => 'CAP123', 'amount' => ['currency_code' => 'USD', 'value' => '1.00'],
                    ]]],
                ]],
            ], 201),
        ]);

        $this->withSession($this->pending('paypal', ['order_id' => 'PP_ORDER_1', 'value' => '250.00']))
            ->postJson('/app/pay/verify', ['order_id' => 'PP_ORDER_1'])
            ->assertStatus(422);
    }

    /* ------------------------------------------------------------- listing */

    public function test_the_new_gateways_are_offered_for_topups(): void
    {
        $this->fake();

        $labels = collect(app(\App\Services\Cabme\CatalogApi::class)->enabledPaymentMethods('topup'))
            ->pluck('label')->all();

        $this->assertContains('PayStack', $labels);
        $this->assertContains('FlutterWave', $labels);
        $this->assertContains('PayPal', $labels);
    }
}
