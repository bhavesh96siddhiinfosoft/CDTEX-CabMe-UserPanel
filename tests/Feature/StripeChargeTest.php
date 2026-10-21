<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Stripe charges must never credit an amount the browser chose, and a rider
 * must not be able to substitute a different (cheaper) intent at verify time.
 */
class StripeChargeTest extends TestCase
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
            'profile' => ['id' => 42, 'email' => 'a@b.test'],
        ]);
    }

    private function fakeAdminAndStripe(array $intent): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://admin.test/api/v1/payment-settings/' => Http::response([
                'success' => 'success', 'error' => null, 'message' => 'ok',
                'Strip' => [['id' => '1', 'isEnabled' => 'true', 'id_payment_method' => '10',
                    'libelle' => 'Stripe', 'secret_key' => 'sk_test_x', 'clientpublishableKey' => 'pk_test_x']],
            ]),
            'https://api.stripe.com/v1/payment_intents/*' => Http::response($intent),
            'https://api.stripe.com/v1/payment_intents' => Http::response([
                'id' => 'pi_NEW', 'client_secret' => 'pi_NEW_secret', 'amount' => 25000, 'status' => 'requires_payment_method',
            ]),
            'https://admin.test/api/v1/settings/' => Http::response([
                'success' => 'success', 'data' => ['currency_code' => 'USD'],
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);
    }

    /** Pending record as PayController stores it for a ₹/$250 parcel charge. */
    private function pending(array $overrides = []): array
    {
        return ['booking_payment_pending' => array_merge([
            'gateway' => 'stripe',
            'intent_id' => 'pi_OURS',
            'reference' => 'parcel_42_abcdef',
            'amount' => 250.0,
            'minor' => 25000,
            'method_id' => 10,
            'context' => 'parcel',
        ], $overrides)];
    }

    public function test_a_succeeded_intent_with_matching_amount_verifies(): void
    {
        $this->fakeAdminAndStripe([
            'id' => 'pi_OURS', 'status' => 'succeeded', 'amount_received' => 25000,
            'metadata' => ['cabme_ref' => 'parcel_42_abcdef'],
        ]);

        $this->withSession($this->pending())
            ->postJson('/app/pay/verify', ['payment_intent_id' => 'pi_OURS'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame('pi_OURS', session('booking_payment_verified.transaction_id'));
        $this->assertSame(250.0, session('booking_payment_verified.amount'));
    }

    public function test_a_substituted_intent_id_is_rejected(): void
    {
        // The rider pays a cheap intent of their own and posts ITS id.
        $this->fakeAdminAndStripe([
            'id' => 'pi_THEIRS', 'status' => 'succeeded', 'amount_received' => 100,
            'metadata' => ['cabme_ref' => 'something_else'],
        ]);

        $this->withSession($this->pending())
            ->postJson('/app/pay/verify', ['payment_intent_id' => 'pi_THEIRS'])
            ->assertStatus(422);

        $this->assertNull(session('booking_payment_verified'));
    }

    public function test_an_unpaid_intent_is_rejected(): void
    {
        $this->fakeAdminAndStripe([
            'id' => 'pi_OURS', 'status' => 'requires_payment_method', 'amount_received' => 0,
            'metadata' => ['cabme_ref' => 'parcel_42_abcdef'],
        ]);

        $this->withSession($this->pending())
            ->postJson('/app/pay/verify', ['payment_intent_id' => 'pi_OURS'])
            ->assertStatus(422);
    }

    public function test_an_amount_mismatch_is_rejected(): void
    {
        // Stripe says less money arrived than the order was for.
        $this->fakeAdminAndStripe([
            'id' => 'pi_OURS', 'status' => 'succeeded', 'amount_received' => 100,
            'metadata' => ['cabme_ref' => 'parcel_42_abcdef'],
        ]);

        $this->withSession($this->pending())
            ->postJson('/app/pay/verify', ['payment_intent_id' => 'pi_OURS'])
            ->assertStatus(422);
    }

    public function test_the_order_endpoint_creates_a_stripe_intent_for_stripe_methods(): void
    {
        $this->fakeAdminAndStripe([]);

        $this->postJson('/app/pay/order', [
            'amount' => 250, 'payment_method_id' => 10, 'context' => 'parcel',
        ])
            ->assertOk()
            ->assertJson(['gateway' => 'stripe', 'publishable_key' => 'pk_test_x'])
            ->assertJsonMissingPath('client_secret_missing');

        $this->assertSame('stripe', session('booking_payment_pending.gateway'));
        $this->assertSame('pi_NEW', session('booking_payment_pending.intent_id'));
    }
}
