<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pay Now for an active ride — the app's ride payment screen. Paying IS
 * completing the ride: the customer's complete-requete call settles it, so
 * the panel must only allow it while the trip is actually ON RIDE, must
 * compute the admin's exact total ((fare − coupon) + taxes), and must demand
 * a verified charge before completing a gateway-paid ride.
 */
class RidePaymentTest extends TestCase
{
    /** montant 100, 10% coupon → 90; GST fixed 5 + IGST 8% of 90 = 7.2 → 102.20 */
    private function onRide(array $overrides = []): array
    {
        return array_merge([
            'id' => 77,
            'id_user_app' => 42,
            'id_conducteur' => 9,
            'statut' => 'on ride',
            'montant' => '100',
            'id_payment_method' => '10',
            'discount_type' => ['type' => 'Percentage', 'value' => '10'],
            'tax' => [
                ['libelle' => 'GST', 'type' => 'Fixed', 'value' => '5'],
                ['libelle' => 'IGST', 'type' => 'Percentage', 'value' => '8'],
            ],
            'latitude_depart' => '23.07', 'longitude_depart' => '72.56',
            'latitude_arrivee' => '23.09', 'longitude_arrivee' => '72.58',
        ], $overrides);
    }

    private function fakeAdmin(array $ride, array $extra = []): void
    {
        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);

        session()->put('cabme_rider', [
            'id' => 42, 'token' => 'tok-123',
            'profile' => ['id' => 42, 'email' => 'a@b.test', 'amount' => '300.00'],
        ]);

        Http::preventStrayRequests();
        // $extra first: stubs match in insertion order, so overrides must sit
        // before the defaults and the '*' catch-all must stay last.
        Http::fake($extra + [
            'https://admin.test/api/v1/user-recent-ride/' => Http::response([
                'success' => 'success', 'data' => $ride,
            ]),
            'https://admin.test/api/v1/payment-settings/' => Http::response([
                'success' => 'success',
                'Cash' => [['id' => '2', 'isEnabled' => 'true', 'id_payment_method' => '5', 'libelle' => 'Cash']],
                'My Wallet' => [['id' => '4', 'isEnabled' => 'true', 'id_payment_method' => '9', 'libelle' => 'Wallet']],
                'Razorpay' => [['id' => '7', 'isEnabled' => 'true', 'id_payment_method' => '13', 'libelle' => 'Razorpay', 'key' => 'rzp_key_x', 'secret_key' => 'rzp_secret_x']],
            ]),
            'https://admin.test/api/v1/complete-requete/' => Http::response([
                'success' => 'success', 'message' => 'Ride completed', 'data' => [],
            ]),
            'https://admin.test/api/v1/profilebyphone/' => Http::response([
                'success' => 'success', 'data' => ['id' => 42, 'email' => 'a@b.test', 'amount' => '300.00'],
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);
    }

    /**
     * Cash is handed over in person: the driver confirms the money and closes
     * the trip, exactly as in the apps. Choosing cash here used to complete the
     * ride outright, which settled it with no driver confirmation at all.
     */
    public function test_cash_only_records_the_payment_method(): void
    {
        $this->fakeAdmin($this->onRide());

        $this->post('/app/ride/77/complete', ['payment_method_id' => 5])
            ->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'change-booking-payment-method')
            && (int) $request['booking_id'] === 77
            && (int) $request['id_payment'] === 5);

        // The ride stays open for the driver to finish.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'complete-requete'));
    }

    public function test_paying_is_blocked_until_the_driver_starts_the_trip(): void
    {
        $this->fakeAdmin($this->onRide(['statut' => 'confirmed']));

        $this->post('/app/ride/77/complete', ['payment_method_id' => 5])
            ->assertSessionHasErrors('pay');

        $this->postJson('/app/ride/77/pay/order', ['payment_method_id' => 13])
            ->assertStatus(422);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'complete-requete'));
    }

    public function test_a_gateway_method_requires_a_verified_charge_first(): void
    {
        $this->fakeAdmin($this->onRide());

        $this->post('/app/ride/77/complete', ['payment_method_id' => 13])
            ->assertSessionHasErrors('pay');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'complete-requete'));
    }

    public function test_a_verified_charge_for_the_exact_total_completes_a_gateway_ride(): void
    {
        $this->fakeAdmin($this->onRide());

        // What PayController::verify leaves behind after a confirmed charge.
        session()->put(\App\Services\Payments\BookingCheckout::VERIFIED, [
            'transaction_id' => 'pay_LIVE123',
            'amount' => 102.20,
            'method_id' => 13,
            'context' => 'ride',
        ]);

        $this->post('/app/ride/77/complete', ['payment_method_id' => 13])
            ->assertRedirect('/app/trips/ride/77');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'complete-requete')
                && $request['transaction_id'] === 'pay_LIVE123'
                && (int) $request['id_payment'] === 13;
        });
    }

    public function test_the_charge_order_uses_the_admin_total_not_the_bare_fare(): void
    {
        $this->fakeAdmin($this->onRide(), [
            'https://api.razorpay.com/v1/orders' => Http::response([
                'id' => 'order_X1', 'amount' => 10220, 'currency' => 'INR', 'status' => 'created',
            ]),
        ]);

        $this->postJson('/app/ride/77/pay/order', ['payment_method_id' => 13])
            ->assertOk()
            ->assertJsonPath('gateway', 'razorpay');

        // (100 − 10%) + 5 + 8% of 90 = 102.20 → 10220 minor units.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.razorpay.com/v1/orders')
                && (int) $request['amount'] === 10220;
        });
    }

    public function test_wallet_payment_needs_the_balance_to_cover_the_total(): void
    {
        $this->fakeAdmin($this->onRide(), [
            'https://admin.test/api/v1/profilebyphone/' => Http::response([
                'success' => 'success', 'data' => ['id' => 42, 'email' => 'a@b.test', 'amount' => '50.00'],
            ]),
        ]);

        session()->put('cabme_rider', [
            'id' => 42, 'token' => 'tok-123',
            'profile' => ['id' => 42, 'email' => 'a@b.test', 'amount' => '50.00'],
        ]);

        $this->post('/app/ride/77/complete', ['payment_method_id' => 9])
            ->assertSessionHasErrors('pay');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'complete-requete'));
    }
}
