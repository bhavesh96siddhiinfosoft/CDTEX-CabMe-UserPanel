<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Wallet top-up must never credit an amount the browser chose. These cover the
 * ways a tampered callback could otherwise get money for free.
 */
class RazorpayTopupTest extends TestCase
{
    private const SECRET = 'test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);
    }

    private function signedIn(array $session = []): self
    {
        return $this->withSession(array_merge([
            'cabme_rider' => [
                'id' => 42, 'token' => 'tok-123',
                'profile' => ['id' => 42, 'email' => 'a@b.test', 'amount' => '0.00'],
            ],
        ], $session));
    }

    private function signature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, self::SECRET);
    }

    /** A pending top-up of ₹500, as createOrder would have recorded it. */
    private function pending(array $overrides = []): array
    {
        return ['topup_payment_pending' => array_merge([
            'gateway' => 'razorpay',
            'order_id' => 'order_ABC',
            'amount' => 500.0,
            'minor' => 50000,
            'method_id' => 13,
            'context' => 'topup',
        ], $overrides)];
    }

    private function fakeRazorpay(array $payment, array $extra = []): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge([
            // Credentials come from the admin's payment settings now.
            'https://admin.test/api/v1/payment-settings/' => Http::response([
                'success' => 'success', 'error' => null, 'message' => 'ok',
                'Razorpay' => [['id' => '7', 'isEnabled' => 'true', 'id_payment_method' => '13',
                    'libelle' => 'Razorpay', 'key' => 'rzp_test_key', 'secret_key' => self::SECRET]],
            ]),
            'https://api.razorpay.com/v1/payments/*' => Http::response($payment),
            'https://admin.test/api/v1/settings/' => Http::response(['success' => 'success', 'data' => []]),
            'https://admin.test/api/v1/amount/' => Http::response(['success' => 'success', 'data' => []]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ], $extra));
    }

    public function test_a_captured_payment_credits_the_recorded_amount(): void
    {
        $this->fakeRazorpay([
            'status' => 'captured', 'amount' => 50000, 'order_id' => 'order_ABC',
        ]);

        $this->signedIn($this->pending())
            ->postJson('/app/wallet/topup/razorpay/verify', [
                'razorpay_order_id' => 'order_ABC',
                'razorpay_payment_id' => 'pay_XYZ',
                'razorpay_signature' => $this->signature('order_ABC', 'pay_XYZ'),
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // The admin is credited with the SERVER's amount, not anything posted.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'v1/amount/')
            && (int) $r['amount'] === 500
            && $r['transaction_id'] === 'pay_XYZ'
            && (int) $r['is_credited'] === 1);
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $this->fakeRazorpay(['status' => 'captured', 'amount' => 50000, 'order_id' => 'order_ABC']);

        $this->signedIn($this->pending())
            ->postJson('/app/wallet/topup/razorpay/verify', [
                'razorpay_order_id' => 'order_ABC',
                'razorpay_payment_id' => 'pay_XYZ',
                'razorpay_signature' => 'not-a-real-signature',
            ])
            ->assertStatus(422);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'v1/amount/'));
    }

    public function test_an_uncaptured_payment_is_rejected(): void
    {
        // Signature is valid but the money was never actually taken.
        $this->fakeRazorpay(['status' => 'created', 'amount' => 50000, 'order_id' => 'order_ABC']);

        $this->signedIn($this->pending())
            ->postJson('/app/wallet/topup/razorpay/verify', [
                'razorpay_order_id' => 'order_ABC',
                'razorpay_payment_id' => 'pay_XYZ',
                'razorpay_signature' => $this->signature('order_ABC', 'pay_XYZ'),
            ])
            ->assertStatus(422);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'v1/amount/'));
    }

    public function test_a_cheaper_payment_cannot_be_replayed_against_a_larger_topup(): void
    {
        // Rider paid ₹1 but the session expects ₹500.
        $this->fakeRazorpay(['status' => 'captured', 'amount' => 100, 'order_id' => 'order_ABC']);

        $this->signedIn($this->pending())
            ->postJson('/app/wallet/topup/razorpay/verify', [
                'razorpay_order_id' => 'order_ABC',
                'razorpay_payment_id' => 'pay_XYZ',
                'razorpay_signature' => $this->signature('order_ABC', 'pay_XYZ'),
            ])
            ->assertStatus(422);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'v1/amount/'));
    }

    public function test_a_payment_for_another_order_is_rejected(): void
    {
        $this->fakeRazorpay(['status' => 'captured', 'amount' => 50000, 'order_id' => 'order_SOMEONE_ELSE']);

        $this->signedIn($this->pending())
            ->postJson('/app/wallet/topup/razorpay/verify', [
                'razorpay_order_id' => 'order_ABC',
                'razorpay_payment_id' => 'pay_XYZ',
                'razorpay_signature' => $this->signature('order_ABC', 'pay_XYZ'),
            ])
            ->assertStatus(422);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'v1/amount/'));
    }

    public function test_verification_without_a_pending_topup_is_rejected(): void
    {
        $this->fakeRazorpay(['status' => 'captured', 'amount' => 50000, 'order_id' => 'order_ABC']);

        $this->signedIn()
            ->postJson('/app/wallet/topup/razorpay/verify', [
                'razorpay_order_id' => 'order_ABC',
                'razorpay_payment_id' => 'pay_XYZ',
                'razorpay_signature' => $this->signature('order_ABC', 'pay_XYZ'),
            ])
            ->assertStatus(422);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'v1/amount/'));
    }

    public function test_a_captured_payment_cannot_be_credited_twice(): void
    {
        $this->fakeRazorpay(['status' => 'captured', 'amount' => 50000, 'order_id' => 'order_ABC']);

        $payload = [
            'razorpay_order_id' => 'order_ABC',
            'razorpay_payment_id' => 'pay_XYZ',
            'razorpay_signature' => $this->signature('order_ABC', 'pay_XYZ'),
        ];

        $session = $this->signedIn($this->pending());
        $session->postJson('/app/wallet/topup/razorpay/verify', $payload)->assertOk();

        // The pending record is consumed, so a replay finds nothing to credit.
        $this->assertNull(session('topup_payment_pending'));
        $this->postJson('/app/wallet/topup/razorpay/verify', $payload)->assertStatus(422);
    }

    public function test_the_order_endpoint_refuses_when_razorpay_is_unconfigured(): void
    {
        // No Razorpay bucket in the admin's payment settings at all.
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['success' => 'success', 'data' => []])]);

        $this->signedIn()
            ->postJson('/app/wallet/topup/razorpay/order', ['amount' => 500, 'payment_method_id' => 13])
            ->assertStatus(422);
    }
}
