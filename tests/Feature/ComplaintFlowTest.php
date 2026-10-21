<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Complaints, as the admin models them: one per booking, keyed by
 * booking_type + booking_id, raised from the trip it is about once that trip
 * is over. There is no free-form complaint — AddComplaintsController rejects
 * anything without a booking.
 */
class ComplaintFlowTest extends TestCase
{
    private function trip(array $overrides = []): array
    {
        return array_merge([
            'id' => 77,
            'id_user_app' => 42,
            'statut' => 'completed',
            'montant' => '100',
            'depart_name' => 'Nirnay Nagar',
            'destination_name' => 'Tharad',
        ], $overrides);
    }

    private function fakeAdmin(array $extra = []): void
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

        // Stubs match in insertion order, so per-test overrides go first.
        Http::fake($extra + [
            'https://admin.test/api/v1/get-booking-details/' => Http::response([
                'success' => 'success', 'data' => $this->trip(),
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);
    }

    /** The admin's "nothing here" answer for a booking with no complaint. */
    private function noComplaint(): array
    {
        return [
            'https://admin.test/api/v1/complaintsList/' => Http::response([
                'success' => 'Failed', 'code' => 404,
                'message' => 'No complaint found for the given details', 'data' => null,
            ]),
        ];
    }

    public function test_a_finished_trip_offers_to_report_an_issue(): void
    {
        $this->fakeAdmin($this->noComplaint());

        $this->get('/app/trips/ride/77')
            ->assertOk()
            ->assertSee('Report an issue')
            ->assertSee('Submit complaint');
    }

    public function test_a_running_trip_does_not_offer_it(): void
    {
        $this->fakeAdmin([
            'https://admin.test/api/v1/get-booking-details/' => Http::response([
                'success' => 'success', 'data' => $this->trip(['statut' => 'on ride']),
            ]),
        ]);

        $this->get('/app/trips/ride/77')
            ->assertOk()
            ->assertDontSee('Report an issue');

        // Nothing to ask about while the trip is still running.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'complaintsList'));
    }

    public function test_submitting_sends_the_booking_and_the_message(): void
    {
        $this->fakeAdmin([
            'https://admin.test/api/v1/complaints/' => Http::response([
                'success' => 'success', 'code' => 200,
                'message' => 'Complaint added successfully',
                'data' => ['id' => 9, 'status' => 'initiated'],
            ]),
        ]);

        $this->post('/app/trips/parcel/502/complaint', [
            'title' => 'Parcel broken',
            'description' => 'The driver damaged my parcel.',
        ])->assertRedirect();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'v1/complaints/')
                && $request['booking_type'] === 'parcel'
                && (int) $request['booking_id'] === 502
                && $request['title'] === 'Parcel broken'
                && $request['description'] === 'The driver damaged my parcel.';
        });
    }

    public function test_an_existing_complaint_is_shown_with_its_status(): void
    {
        $this->fakeAdmin([
            'https://admin.test/api/v1/complaintsList/' => Http::response([
                'success' => 'success', 'code' => 200,
                'data' => [
                    'id' => '24', 'title' => 'Incorrect drop location',
                    'description' => 'Dropped me somewhere else.',
                    'status' => 'processing',
                    'booking_id' => '77', 'booking_type' => 'ride',
                    'created_at' => '03-09-2025 02:00 PM',
                ],
            ]),
        ]);

        $this->get('/app/trips/ride/77')
            ->assertOk()
            ->assertSee('Your complaint')
            ->assertSee('Incorrect drop location')
            ->assertSee('In review')          // the `processing` pill
            ->assertSee('03 Sep 2025')        // d-m-Y read the right way round
            ->assertDontSee('Report an issue');
    }

    public function test_a_second_complaint_on_the_same_booking_is_refused(): void
    {
        $this->fakeAdmin([
            'https://admin.test/api/v1/complaints/' => Http::response([
                'success' => 'Failed', 'error' => 'Complaint Already Submitted',
            ]),
        ]);

        $this->post('/app/trips/ride/77/complaint', [
            'title' => 'Again', 'description' => 'Same trip, second complaint.',
        ])->assertSessionHasErrors('title');
    }

    public function test_the_support_page_lists_every_complaint_with_its_status(): void
    {
        $this->fakeAdmin([
            'https://admin.test/api/v1/complaintsList/' => Http::response([
                'success' => 'success', 'code' => 200,
                'data' => [
                    [
                        'id' => '25', 'title' => 'Parcel broken',
                        'description' => 'Driver broke my parcel.', 'status' => 'completed',
                        'booking_id' => '502', 'booking_type' => 'parcel',
                        'created_at' => '03-09-2025 05:07 PM',
                    ],
                ],
            ]),
        ]);

        $this->get('/app/support')
            ->assertOk()
            ->assertSee('Parcel broken')
            ->assertSee('Completed')
            ->assertSee('Parcel #502')
            ->assertSee('/app/trips/parcel/502');

        // The rider's own complaints, not a booking lookup.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'complaintsList')
                && (int) $request['user_id'] === 42
                && $request['user_type'] === 'customer';
        });
    }
}
