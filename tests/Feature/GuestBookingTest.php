<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guests compose a trip on the map-first landing page, then sign in to price
 * and book it — fares need a rider token, so they cannot be shown before that.
 */
class GuestBookingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);

        Http::preventStrayRequests();
        Http::fake([
            'https://admin.test/api/v1/user-recent-ride/' => Http::response([
                'success' => 'Failed', 'message' => 'No data found', 'data' => null,
            ]),
            'https://admin.test/api/v1/settings/' => Http::response([
                'success' => 'success',
                'data' => ['title' => 'CabMe', 'active_services' => ['ride', 'parcel', 'rental'], 'currency' => '$'],
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);
    }

    private function route(): array
    {
        return [
            'pickup_lat' => 19.076, 'pickup_lng' => 72.8777, 'pickup_address' => 'Mumbai',
            'drop_lat' => 18.5204, 'drop_lng' => 73.8567, 'drop_address' => 'Pune',
        ];
    }

    public function test_the_landing_page_leads_with_the_map_picker(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('placePicker()', $html);
        $this->assertStringContainsString('action="'.route('home.start').'"', $html);
    }

    public function test_a_signed_in_rider_skips_the_marketing_page(): void
    {
        $this->withSession(['cabme_rider' => [
            'id' => 42, 'token' => 'tok-123', 'profile' => ['id' => 42],
        ]])->get('/')->assertRedirect(route('app.home'));
    }

    public function test_a_guest_trip_is_stashed_and_sent_to_sign_in(): void
    {
        $this->post('/start', $this->route())
            ->assertRedirect(route('login'))
            ->assertSessionHas('pending_route');

        $this->assertSame(19.076, session('pending_route')['pickup_lat']);
        $this->assertSame('Pune', session('pending_route')['drop_address']);
    }

    public function test_an_incomplete_trip_is_rejected(): void
    {
        $this->post('/start', ['pickup_lat' => 19.076])
            ->assertSessionHasErrors(['pickup_lng', 'drop_lat', 'drop_lng']);

        $this->assertNull(session('pending_route'));
    }

    public function test_the_stashed_trip_prefills_the_booking_form_once(): void
    {
        $session = ['cabme_rider' => ['id' => 42, 'token' => 'tok-123', 'profile' => ['id' => 42]]];

        $html = $this->withSession($session + ['pending_route' => $this->route()])
            ->get('/app/ride')->assertOk()->getContent();

        // The carried route reaches the picker as its initial state.
        $this->assertStringContainsString('19.076', $html);
        $this->assertStringContainsString('Pune', $html);

        // And is consumed, so a later visit starts clean rather than resurrecting it.
        $this->assertNull(session('pending_route'));
    }
}
