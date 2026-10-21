<?php

namespace Tests\Feature;

use App\Services\Payments\BookingCheckout;
use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A parcel is priced per km AND per kg, and — unlike a ride — it is paid for
 * up front. That splits the money in two, exactly as the app does it:
 *
 *  - the booking stores the SUBTOTAL, plus the coupon id and the admin's own
 *    tax snapshot, and the admin bills (subtotal − discount) + tax when the
 *    delivery completes;
 *  - the rider is charged that same total now.
 *
 * Getting either half wrong means charging the wrong amount, so both are
 * pinned here.
 */
class ParcelBookingTest extends TestCase
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

    /** @param  array<string, mixed>  $extra */
    private function fakeAdmin(array $extra = []): void
    {
        Http::fake($extra + [
            'https://admin.test/api/v1/settings/' => Http::response([
                'success' => 'success',
                'data' => [
                    'title' => 'CabMe',
                    'active_services' => ['ride', 'parcel', 'rental'],
                    'currency' => '$',
                    'delivery_distance' => 'KM',
                    'map_for_application' => 'OSM',
                    // The admin's active taxes, as v1/settings returns them.
                    'tax' => [
                        ['libelle' => 'GST', 'type' => 'Fixed', 'value' => '5', 'country' => 'India', 'statut' => 'yes'],
                        ['libelle' => 'IGST', 'type' => 'Percentage', 'value' => '10', 'country' => 'India', 'statut' => 'yes'],
                    ],
                ],
            ]),
            'https://nominatim.openstreetmap.org/reverse*' => Http::response(['address' => ['country' => 'India']]),
            'https://admin.test/api/v1/parcel-category-basefare/' => Http::response([
                'success' => 'success',
                'data' => [[
                    'id' => '3', 'title' => 'Documents', 'image' => null,
                    // 10km × 5 + 4kg × 25 = 150
                    'base_fare' => '150.00', 'per_km_charge' => '5', 'per_kg_charge' => '25',
                ]],
            ]),
            'https://admin.test/api/v1/discount-list/' => Http::response([
                'success' => 'success',
                'data' => [['id' => '7', 'code' => 'SAVE10', 'type' => 'Percentage', 'discount' => '10']],
            ]),
            'https://admin.test/api/v1/payment-settings/' => Http::response([
                'success' => 'success',
                'Cash' => [['id' => '2', 'isEnabled' => 'true', 'id_payment_method' => '5', 'libelle' => 'Cash']],
                'My Wallet' => [['id' => '4', 'isEnabled' => 'true', 'id_payment_method' => '9', 'libelle' => 'Wallet']],
            ]),
            'https://admin.test/api/v1/parcel-register' => Http::response([
                'success' => 'success', 'message' => 'Parcel booked', 'data' => ['id' => 99],
            ]),
            'https://admin.test/api/v1/profilebyphone/' => Http::response([
                'success' => 'success', 'data' => ['id' => 42, 'email' => 'a@b.test', 'amount' => '5000.00'],
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);
    }

    /**
     * The admin call is multipart when photos are attached and a plain form
     * post otherwise; flatten both to name => value.
     *
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        if (isset($data[0]['name'])) {
            return collect($data)->pluck('contents', 'name')->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function booking(array $overrides = []): array
    {
        return array_merge([
            'pickup_lat' => '23.02', 'pickup_lng' => '72.53',
            'drop_lat' => '23.09', 'drop_lng' => '72.58',
            'pickup_address' => 'Paldi', 'drop_address' => 'Ranip',
            'distance' => '10',
            'category_id' => 3,
            // Wallet: a parcel is paid at booking, so cash is not offered.
            'payment_method_id' => 9,
            'sender_name' => 'Thakarshi', 'sender_phone' => '9876543210',
            'receiver_name' => 'Alia', 'receiver_phone' => '9876500000',
            'weight' => '4', 'dimension' => '3',
            'note' => 'Handle with care',
            'pickup_date' => now()->toDateString(), 'pickup_time' => '10:00',
            'receive_date' => now()->toDateString(), 'receive_time' => '15:00',
            'amount' => '150',
        ], $overrides);
    }

    public function test_the_quote_prices_on_weight_as_well_as_distance(): void
    {
        $this->fakeAdmin();

        $this->postJson('/app/parcel/quote', [
            'pickup_lat' => '23.02', 'pickup_lng' => '72.53',
            'drop_lat' => '23.09', 'drop_lng' => '72.58',
            'weight' => 4,
        ])->assertOk();

        // A weightless quote is the bug this flow had: the admin charges per
        // kg, so the weight has to reach it.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'parcel-category-basefare')
                && (float) $request['parcel_weight'] === 4.0;
        });
    }

    public function test_the_quote_returns_the_taxes_that_will_be_billed(): void
    {
        $this->fakeAdmin();

        $this->postJson('/app/parcel/quote', [
            'pickup_lat' => '23.02', 'pickup_lng' => '72.53',
            'drop_lat' => '23.09', 'drop_lng' => '72.58',
            'weight' => 4,
        ])
            ->assertOk()
            ->assertJsonCount(2, 'taxes')
            ->assertJsonPath('taxes.0.libelle', 'GST');
    }

    /**
     * ParcelRegisterController reads the photos from `parcel_image`. Posting
     * them under the form's own field name meant the admin saw no file at all
     * and stored its placeholder, so nothing showed on the detail page, in the
     * admin, or in the apps.
     */
    /**
     * ParcelRegisterController reads the photos from `parcel_image`. Posting
     * them under the form's own field name meant the admin saw no file at all
     * and stored its placeholder, so nothing appeared on the detail page, in
     * the admin, or in the apps.
     */
    public function test_photos_are_attached_under_the_name_the_admin_reads(): void
    {
        $this->fakeAdmin();

        $this->post('/app/parcel', $this->booking([
            'images' => [
                \Illuminate\Http\UploadedFile::fake()->image('one.jpg'),
                \Illuminate\Http\UploadedFile::fake()->image('two.jpg'),
            ],
        ]))->assertRedirect();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'parcel-register')) {
                return false;
            }

            $body = (string) $request->body();

            return str_contains($body, 'name="parcel_image[0]"')
                && str_contains($body, 'name="parcel_image[1]"');
        });
    }

    public function test_the_booking_stores_the_subtotal_and_the_coupon(): void
    {
        $this->fakeAdmin();

        $this->post('/app/parcel', $this->booking(['coupon_id' => 7]))
            ->assertRedirect('/app/trips/parcel');

        // The admin gets the raw subtotal plus the coupon id — it applies the
        // discount and its own taxes when the delivery completes.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'parcel-register')) {
                return false;
            }

            $body = $this->fields($request->data());

            return (float) $body['amount'] === 150.0
                && (int) $body['discount_id'] === 7
                && (float) $body['parcel_weight'] === 4.0
                && $body['note'] === 'Handle with care';
        });
    }

    public function test_a_wallet_booking_is_charged_subtotal_less_coupon_plus_tax(): void
    {
        // Wallet is settled by the admin at register, so the panel only gates
        // on the balance — with a balance below the taxed total, the booking
        // must be refused rather than quietly under-charged.
        $this->fakeAdmin([
            'https://admin.test/api/v1/profilebyphone/' => Http::response([
                'success' => 'success',
                // 150 − 10% = 135, + 5 fixed + 10% of 135 = 153.50 due.
                'data' => ['id' => 42, 'email' => 'a@b.test', 'amount' => '150.00'],
            ]),
        ]);

        session()->put('cabme_rider', [
            'id' => 42, 'token' => 'tok-123',
            'profile' => ['id' => 42, 'email' => 'a@b.test', 'amount' => '150.00'],
        ]);

        $this->post('/app/parcel', $this->booking(['payment_method_id' => 9, 'coupon_id' => 7]))
            ->assertSessionHasErrors('booking');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'parcel-register'));
    }

    public function test_a_gateway_booking_needs_a_verified_charge_for_the_taxed_total(): void
    {
        $this->fakeAdmin([
            'https://admin.test/api/v1/payment-settings/' => Http::response([
                'success' => 'success',
                'Razorpay' => [['id' => '7', 'isEnabled' => 'true', 'id_payment_method' => '13', 'libelle' => 'Razorpay', 'key' => 'rzp_key_test', 'secret_key' => 'rzp_secret_test']],
            ]),
        ]);

        // Paid only the subtotal — the taxed total is what is owed.
        $this->withSession([BookingCheckout::VERIFIED => [
            'transaction_id' => 'pay_1', 'amount' => 150.00, 'method_id' => 13, 'context' => 'parcel',
        ]])->post('/app/parcel', $this->booking(['payment_method_id' => 13, 'coupon_id' => 7]))
            ->assertSessionHasErrors('booking');

        // 150 − 15 (coupon) + 5 (GST) + 13.50 (IGST) = 153.50
        $this->withSession([BookingCheckout::VERIFIED => [
            'transaction_id' => 'pay_2', 'amount' => 153.50, 'method_id' => 13, 'context' => 'parcel',
        ]])->post('/app/parcel', $this->booking(['payment_method_id' => 13, 'coupon_id' => 7]))
            ->assertRedirect('/app/trips/parcel');
    }
}
