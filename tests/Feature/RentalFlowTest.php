<?php

namespace Tests\Feature;

use App\Services\Payments\BookingCheckout;
use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A rental's price is not known when it is booked: the driver files the final
 * km at the end, and the admin then rewrites the order's amount with the extra
 * distance and time before the rider pays. So, as in the app:
 *
 *  - booking charges NOTHING and asks for no end time (the driver's final-km
 *    call sets the real one);
 *  - the tracking screen becomes a payment screen once that call has landed,
 *    and settles through rental-complete.
 */
class RentalFlowTest extends TestCase
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
            'profile' => ['id' => 42, 'email' => 'a@b.test', 'phone' => '9876543210', 'amount' => '5000.00'],
        ]);

        Http::preventStrayRequests();
    }

    /** A rental as the admin's details endpoint returns it. */
    private function order(array $overrides = []): array
    {
        return array_merge([
            'id' => 55,
            'id_user_app' => 42,
            'id_conducteur' => 9,
            'status' => 'on ride',
            'amount' => '200',            // base 150 + extras the driver filed
            'current_km' => '10',
            'complete_km' => '85',
            'id_payment_method' => '5',
            'lat_source' => '23.02', 'lng_source' => '72.53',
            'depart_name' => 'Paldi',
            'start_date' => '2026-09-21', 'start_time' => '10:00',
            'discount_type' => ['type' => 'Percentage', 'value' => '10'],
            'tax' => [
                ['libelle' => 'GST', 'type' => 'Fixed', 'value' => '5'],
                ['libelle' => 'IGST', 'type' => 'Percentage', 'value' => '10'],
            ],
        ], $overrides);
    }

    private function fakeAdmin(array $order, array $extra = []): void
    {
        Http::fake($extra + [
            'https://admin.test/api/v1/get-rental-booking-details/' => Http::response([
                'success' => 'success', 'data' => $order,
            ]),
            'https://admin.test/api/v1/get-rental-packages/' => Http::response([
                'success' => 'success',
                'data' => [[
                    'id' => '1', 'title' => '4 hr city', 'description' => 'Best for short trips',
                    'baseFare' => '150', 'includedHours' => '4', 'includedDistance' => '40',
                    'vehicleTypeId' => '63',
                ]],
            ]),
            'https://admin.test/api/v1/rental-register' => Http::response([
                'success' => 'success', 'data' => ['id' => 55],
            ]),
            'https://admin.test/api/v1/rental-complete' => Http::response([
                'success' => 'success', 'message' => 'Rental completed', 'data' => [],
            ]),
            'https://admin.test/api/v1/payment-settings/' => Http::response([
                'success' => 'success',
                'Cash' => [['id' => '2', 'isEnabled' => 'true', 'id_payment_method' => '5', 'libelle' => 'Cash']],
                'My Wallet' => [['id' => '4', 'isEnabled' => 'true', 'id_payment_method' => '9', 'libelle' => 'Wallet']],
                'Razorpay' => [['id' => '7', 'isEnabled' => 'true', 'id_payment_method' => '13', 'libelle' => 'Razorpay', 'key' => 'rzp_key_test', 'secret_key' => 'rzp_secret_test']],
            ]),
            'https://admin.test/api/v1/profilebyphone/' => Http::response([
                'success' => 'success', 'data' => ['id' => 42, 'email' => 'a@b.test', 'amount' => '5000.00'],
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);
    }

    /** @return array<string, mixed> */
    private function booking(array $overrides = []): array
    {
        return array_merge([
            'pickup_lat' => '23.02', 'pickup_lng' => '72.53',
            'pickup_address' => 'Paldi',
            'package_id' => 1,
            'vehicle_type_id' => 63,
            'payment_method_id' => 5,
            'amount' => '150',
            'start_date' => '2026-09-21',
            'start_time' => '10:00',
        ], $overrides);
    }

    public function test_booking_charges_nothing_and_needs_no_end_time(): void
    {
        $this->fakeAdmin($this->order());

        // No end_date/end_time in the form at all.
        $this->post('/app/rental', $this->booking())
            ->assertRedirect('/app/rental/55/track');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'rental-register')) {
                return false;
            }

            // The admin still wants an end on the row, so the package's own
            // four included hours give it one: 10:00 + 4h.
            return $request['end_date'] === '2026-09-21'
                && $request['end_time'] === '14:00'
                && (float) $request['amount'] === 150.0
                && str_starts_with((string) $request['transaction_id'], 'RENTAL-');
        });
    }

    public function test_a_rental_cannot_be_paid_before_the_driver_files_the_final_km(): void
    {
        $this->fakeAdmin($this->order(['complete_km' => null]));

        $this->post('/app/rental/55/complete', ['payment_method_id' => 5])
            ->assertSessionHasErrors('pay');

        $this->postJson('/app/rental/55/pay/order', ['payment_method_id' => 13])
            ->assertStatus(422);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'rental-complete'));
    }

    /**
     * Cash is handed to the driver, who confirms it and closes the rental —
     * the same rule as a ride. The panel only records the choice.
     */
    public function test_cash_only_records_the_payment_method(): void
    {
        $this->fakeAdmin($this->order());

        $this->post('/app/rental/55/complete', ['payment_method_id' => 5])
            ->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'change-booking-payment-method')
            && $request['booking_type'] === 'rental'
            && (int) $request['booking_id'] === 55
            && (int) $request['id_payment'] === 5);

        // The rental stays open for the driver to finish.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'rental-complete'));
    }

    public function test_the_charge_uses_the_final_amount_the_driver_fixed(): void
    {
        $this->fakeAdmin($this->order(), [
            'https://api.razorpay.com/v1/orders' => Http::response([
                'id' => 'order_R1', 'amount' => 20300, 'currency' => 'INR', 'status' => 'created',
            ]),
        ]);

        $this->postJson('/app/rental/55/pay/order', ['payment_method_id' => 13])
            ->assertOk()
            ->assertJsonPath('gateway', 'razorpay');

        // 200 − 10% = 180, + 5 fixed + 10% of 180 = 203.00 → 20300 minor.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.razorpay.com/v1/orders')
                && (int) $request['amount'] === 20300;
        });
    }

    public function test_a_gateway_rental_needs_a_verified_charge_for_that_total(): void
    {
        $this->fakeAdmin($this->order());

        // Paid the pre-tax figure only.
        $this->withSession([BookingCheckout::VERIFIED => [
            'transaction_id' => 'pay_1', 'amount' => 180.00, 'method_id' => 13, 'context' => 'rental',
        ]])->post('/app/rental/55/complete', ['payment_method_id' => 13])
            ->assertSessionHasErrors('pay');

        $this->withSession([BookingCheckout::VERIFIED => [
            'transaction_id' => 'pay_2', 'amount' => 203.00, 'method_id' => 13, 'context' => 'rental',
        ]])->post('/app/rental/55/complete', ['payment_method_id' => 13])
            ->assertRedirect('/app/trips/rental/55');
    }

    public function test_another_riders_rental_cannot_be_paid(): void
    {
        $this->fakeAdmin($this->order(['id_user_app' => 999]));

        $this->post('/app/rental/55/complete', ['payment_method_id' => 5])
            ->assertSessionHasErrors('pay');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'rental-complete'));
    }
}
