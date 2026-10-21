<?php

namespace Tests\Feature;

use App\Services\Cabme\CatalogApi;
use App\Services\Payments\BookingCheckout;
use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pay-first bookings must mirror what the admin's controllers actually do:
 * the ADMIN debits wallet at parcel-register (so the panel only gates on
 * balance), rentals have no wallet handling at all, and a gateway method is
 * only usable after a server-verified charge for the exact amount.
 */
class BookingCheckoutTest extends TestCase
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
            'profile' => ['id' => 42, 'email' => 'a@b.test', 'amount' => '300.00'],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://admin.test/api/v1/payment-settings/' => Http::response([
                'success' => 'success', 'error' => null, 'message' => 'ok',
                'Cash' => [['id' => '2', 'isEnabled' => 'true', 'id_payment_method' => '5', 'libelle' => 'Cash']],
                'My Wallet' => [['id' => '4', 'isEnabled' => 'true', 'id_payment_method' => '9', 'libelle' => 'Wallet']],
                'Razorpay' => [['id' => '7', 'isEnabled' => 'true', 'id_payment_method' => '13', 'libelle' => 'Razorpay', 'key' => 'rzp_key_x', 'secret_key' => 'rzp_secret_x']],
                'Strip' => [['id' => '1', 'isEnabled' => 'true', 'id_payment_method' => '10', 'libelle' => 'Stripe', 'key' => 'sk_test_x']],
            ]),
            // Profile refresh during the wallet balance check.
            'https://admin.test/api/v1/profilebyphone/' => Http::response([
                'success' => 'success', 'data' => ['id' => 42, 'email' => 'a@b.test', 'amount' => '300.00'],
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);
    }

    private function checkout(): BookingCheckout
    {
        return app(BookingCheckout::class);
    }

    /* ------------------------------------------------------ context rules */

    public function test_contexts_offer_only_what_can_actually_be_settled(): void
    {
        $catalog = app(CatalogApi::class);

        $labels = fn (string $ctx) => array_column($catalog->enabledPaymentMethods($ctx), 'label');

        // Ride: nothing charged at booking, everything stays offerable.
        $this->assertEqualsCanonicalizing(['Cash', 'Wallet', 'Razorpay', 'Stripe'], $labels('ride'));

        // Parcel: paid at booking, before a driver exists — so no cash.
        $this->assertEqualsCanonicalizing(['Wallet', 'Razorpay', 'Stripe'], $labels('parcel'));

        // Rental: nothing is charged at booking either — the rider pays at
        // the end through rental-complete, which settles a wallet properly.
        $this->assertEqualsCanonicalizing(['Cash', 'Wallet', 'Razorpay', 'Stripe'], $labels('rental'));

        // Top-up: chargeable gateways only.
        $this->assertEqualsCanonicalizing(['Razorpay', 'Stripe'], $labels('topup'));
    }

    /* ------------------------------------------------------------ resolve */

    public function test_cash_resolves_immediately_with_a_local_reference(): void
    {
        // Rides and rentals are settled with the driver present.
        $result = $this->checkout()->resolve('rental', 5, 250.0);

        $this->assertTrue($result['ok']);
        $this->assertStringStartsWith('CASH-', $result['transaction_id']);
    }

    public function test_cash_is_not_offered_for_a_parcel(): void
    {
        // Nobody to hand it to: a parcel is paid before a driver is
        // assigned, so cash must not even resolve.
        $result = $this->checkout()->resolve('parcel', 5, 250.0);

        $this->assertFalse($result['ok']);
    }

    public function test_wallet_passes_when_the_balance_covers_the_fare(): void
    {
        $result = $this->checkout()->resolve('parcel', 9, 250.0);

        $this->assertTrue($result['ok']);
        $this->assertStringStartsWith('WALLET-', $result['transaction_id']);

        // The panel must NOT debit — the admin does that inside register.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'v1/amount/'));
    }

    public function test_wallet_fails_when_the_balance_is_short(): void
    {
        $result = $this->checkout()->resolve('parcel', 9, 400.0);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('balance', $result['error']);
    }

    public function test_wallet_is_accepted_for_rentals_once_the_bill_is_final(): void
    {
        // A rental is paid at the END, through rental-complete, and that call
        // debits the wallet the same way a ride's does — so the panel only
        // has to confirm the balance covers the final bill.
        $result = $this->checkout()->resolve('rental', 9, 50.0);

        $this->assertTrue($result['ok']);
        $this->assertStringStartsWith('WALLET-', $result['transaction_id']);
    }

    public function test_a_rental_beyond_the_wallet_balance_is_refused(): void
    {
        $result = $this->checkout()->resolve('rental', 9, 5000.0);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not cover', $result['error']);
    }

    public function test_a_gateway_method_requires_a_verified_charge(): void
    {
        $result = $this->checkout()->resolve('parcel', 13, 250.0);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('payment first', $result['error']);
    }

    public function test_a_verified_charge_is_used_and_consumed(): void
    {
        session()->put(BookingCheckout::VERIFIED, [
            'transaction_id' => 'pay_ABC', 'amount' => 250.0, 'method_id' => 13, 'context' => 'parcel',
        ]);

        $result = $this->checkout()->resolve('parcel', 13, 250.0);

        $this->assertTrue($result['ok']);
        $this->assertSame('pay_ABC', $result['transaction_id']);

        // Consumed: the same charge cannot pay for a second booking.
        $again = $this->checkout()->resolve('parcel', 13, 250.0);
        $this->assertFalse($again['ok']);
    }

    public function test_a_verified_charge_for_a_different_amount_is_rejected(): void
    {
        session()->put(BookingCheckout::VERIFIED, [
            'transaction_id' => 'pay_ABC', 'amount' => 10.0, 'method_id' => 13, 'context' => 'parcel',
        ]);

        $result = $this->checkout()->resolve('parcel', 13, 250.0);

        $this->assertFalse($result['ok']);
    }
}
