<?php

namespace App\Http\Controllers;

use App\Services\Cabme\CatalogApi;
use App\Services\Cabme\RideApi;
use App\Services\GeoService;
use App\Support\CabmeSettings;
use Illuminate\Http\Request;

class RideController extends Controller
{
    public function __construct(
        private readonly RideApi $rides,
        private readonly CatalogApi $catalog,
        private readonly GeoService $geo,
        private readonly CabmeSettings $settings,
    ) {
    }

    public function create()
    {
        abort_unless($this->settings->serviceEnabled('ride'), 404);

        // A rider may only hold one active ride, so send them straight to it
        // rather than letting them fill in a form the admin will reject.
        if ($this->rides->active()->ok) {
            return redirect()->route('app.ride.active');
        }

        // A trip composed on the public landing page before signing in.
        $pending = session()->pull('pending_route');

        return view('ride.create', [
            'coupons' => $this->catalog->coupons('ride')->list(),
            'paymentMethods' => $this->catalog->enabledPaymentMethods(),
            'initialRoute' => $pending ? [
                'pickup' => [
                    'lat' => $pending['pickup_lat'],
                    'lng' => $pending['pickup_lng'],
                    'address' => $pending['pickup_address'] ?? '',
                ],
                'drop' => [
                    'lat' => $pending['drop_lat'],
                    'lng' => $pending['drop_lng'],
                    'address' => $pending['drop_address'] ?? '',
                ],
            ] : null,
        ]);
    }

    /**
     * Price a route. Called by the booking form once both ends are chosen;
     * returns every vehicle type with its fare so the rider can compare.
     */
    public function quote(Request $request)
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric'],
            'pickup_lng' => ['required', 'numeric'],
            'drop_lat' => ['required', 'numeric'],
            'drop_lng' => ['required', 'numeric'],
            // Intermediate stops, in ride order (max 3, as in the app).
            'stops' => ['nullable', 'array', 'max:3'],
            'stops.*.latitude' => ['required_with:stops', 'numeric'],
            'stops.*.longitude' => ['required_with:stops', 'numeric'],
        ]);

        $via = array_map(
            fn ($s) => [(float) $s['latitude'], (float) $s['longitude']],
            $data['stops'] ?? [],
        );

        $route = $this->geo->route($data['pickup_lat'], $data['pickup_lng'], $data['drop_lat'], $data['drop_lng'], $via);

        if (! $route) {
            // Fall back to straight-line so the rider still gets a price;
            // the admin re-prices on booking anyway.
            $route = [
                'distance' => $this->geo->haversine($data['pickup_lat'], $data['pickup_lng'], $data['drop_lat'], $data['drop_lng']),
                'duration' => null,
                'unit' => $this->settings->distanceUnit(),
                'estimated' => true,
            ];
        }

        $result = $this->catalog->vehicleTypesWithFare($data + ['distance' => $route['distance']]);

        if ($result->failed()) {
            return response()->json(['message' => $result->messageOr(__('Could not price this trip.'))], 422);
        }

        return response()->json([
            'route' => $route,
            'vehicle_types' => $result->list(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric'],
            'pickup_lng' => ['required', 'numeric'],
            'drop_lat' => ['required', 'numeric'],
            'drop_lng' => ['required', 'numeric'],
            'pickup_address' => ['required', 'string', 'max:500'],
            'drop_address' => ['required', 'string', 'max:500'],
            'vehicle_type_id' => ['required', 'integer'],
            'distance' => ['required', 'numeric', 'min:0'],
            'duration' => ['required', 'numeric', 'min:0'],
            'sub_total' => ['required', 'numeric', 'min:0'],
            'surge_multiplier' => ['nullable', 'numeric', 'min:1'],
            'passengers' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['required', 'integer', 'min:0', 'max:20'],
            'payment_method_id' => ['nullable', 'integer'],
            'coupon_id' => ['nullable', 'integer'],
            // JSON string of [{latitude, longitude, address}] from the form.
            'stops' => ['nullable', 'json'],
        ]);

        if (! empty($data['stops'])) {
            $data['stops'] = json_decode($data['stops'], true) ?: null;
        }

        $result = $this->rides->book($data);

        if ($result->failed()) {
            return back()->withInput()
                ->withErrors(['booking' => $result->messageOr(__('Could not place your booking.'))]);
        }

        return redirect()->route('app.ride.active')
            ->with('status', __('Booking placed. Looking for a driver nearby.'));
    }

    /** Live tracking shell — the map and status refresh from status() below. */
    public function active()
    {
        $result = $this->rides->active();

        if ($result->failed() || ! is_array($result->data)) {
            return redirect()->route('app.ride.create')
                ->with('status', __('You have no ride in progress.'));
        }

        $ride = $result->data;

        // Road path pickup → drop for the map. Best effort: a routing outage
        // just means the map shows markers without the line.
        $path = null;
        if (! empty($ride['latitude_depart']) && ! empty($ride['latitude_arrivee'])) {
            $route = $this->geo->route(
                (float) $ride['latitude_depart'],
                (float) $ride['longitude_depart'],
                (float) $ride['latitude_arrivee'],
                (float) $ride['longitude_arrivee'],
            );
            $path = $route['geometry'] ?? null;
        }

        // The start code is a feature switch in the admin (Settings → Ride).
        if ($this->settings->get('show_ride_otp') !== 'yes') {
            $ride['otp'] = null;
        }

        return view('ride.active', [
            'ride' => $ride,
            'path' => $path,
            'pollInterval' => config('cabme.poll_interval'),
            // Rides charge nothing at booking. Once the trip is ON RIDE the
            // rider pays from this screen (the app's Pay Now) — paying IS
            // completing the ride.
            'paymentMethods' => $this->catalog->enabledPaymentMethods('ride'),
            'due' => $this->totalDue($ride),
        ]);
    }

    /**
     * What the completion call will charge: (fare − coupon) + taxes, exactly
     * as the admin recomputes it from the booking's own stored coupon and tax
     * snapshot. Rentals settle the same way, so the maths lives in one place.
     *
     * @return array{fare:float, discount:float, tax:float, total:float}
     */
    private function totalDue(array $ride): array
    {
        $bill = \App\Support\BookingBill::due(
            (float) ($ride['montant'] ?? 0),
            $ride['discount_type'] ?? null,
            $ride['tax'] ?? null,
        );

        return ['fare' => $bill['amount']] + $bill;
    }

    /** The rider's own ride, only while it can be paid (driver on the trip). */
    private function payableRide(int $rideId): ?array
    {
        $result = $this->rides->active();
        $ride = $result->ok && is_array($result->data) ? $result->data : null;

        if (! $ride || (int) ($ride['id'] ?? 0) !== $rideId || ($ride['statut'] ?? '') !== 'on ride') {
            return null;
        }

        return $ride;
    }

    /**
     * Start a gateway charge for the ride total. The amount is decided HERE —
     * the browser only names the method. Verification runs through the shared
     * /app/pay/verify poll, exactly like parcel and rental payments.
     */
    public function payOrder(Request $request, int $ride)
    {
        $data = $request->validate(['payment_method_id' => ['required', 'integer']]);

        $current = $this->payableRide($ride);

        if (! $current) {
            return response()->json(['message' => __('This ride cannot be paid right now.')], 422);
        }

        $due = $this->totalDue($current);

        $charge = app(\App\Services\Payments\GatewayCharge::class)
            ->start('ride', (int) $data['payment_method_id'], $due['total']);

        if (! $charge['ok']) {
            return response()->json(['message' => $charge['error']], 422);
        }

        $request->session()->put(PayController::PENDING, $charge['pending']);

        return response()->json($charge['client']);
    }

    /**
     * Pay and finish the ride — the app's Pay Now. Cash completes directly
     * (the driver collects), wallet is debited by the admin inside complete,
     * and a gateway method must have a server-verified charge in the session.
     */
    public function complete(Request $request, int $ride)
    {
        $data = $request->validate(['payment_method_id' => ['required', 'integer']]);

        $current = $this->payableRide($ride);

        if (! $current) {
            return back()->withErrors(['pay' => __('This ride cannot be paid right now.')]);
        }

        $due = $this->totalDue($current);

        // Cash never completes a ride from here. The rider pays the driver,
        // the driver confirms it and closes the trip — the same order the
        // apps follow. All the panel does is record the choice.
        $method = collect(app(\App\Services\Cabme\CatalogApi::class)->enabledPaymentMethods('ride'))
            ->firstWhere('id', (int) $data['payment_method_id']);

        if (($method['kind'] ?? null) === 'offline') {
            $changed = $this->rides->changePaymentMethod($ride, (int) $data['payment_method_id']);

            return $changed->ok
                ? back()->with('status', __('Cash selected. Pay your driver — they will confirm it and end the trip.'))
                : back()->withErrors(['pay' => $changed->messageOr(__('Could not change the payment method.'))]);
        }

        $payment = app(\App\Services\Payments\BookingCheckout::class)
            ->resolve('ride', (int) $data['payment_method_id'], $due['total']);

        if (! $payment['ok']) {
            return back()->withErrors(['pay' => $payment['error']]);
        }

        $done = $this->rides->complete($current, (int) $data['payment_method_id'], $payment['transaction_id']);

        if ($done->failed()) {
            return back()->withErrors(['pay' => $done->messageOr(__('Could not complete the payment. Please try again.'))]);
        }

        return redirect()->route('app.trips.show', ['ride', $ride])
            ->with('status', __('Payment successful — thanks for riding!'));
    }

    /** Polled by the tracking page. The admin has no ride broadcast channels. */
    public function status()
    {
        $result = $this->rides->active();

        if ($result->failed() || ! is_array($result->data)) {
            return response()->json(['active' => false]);
        }

        $ride = $result->data;
        [$path, $pathKey] = $this->driverLeg($ride);

        return response()->json([
            'active' => true,
            'id' => $ride['id'] ?? null,
            'status' => $ride['statut'] ?? null,
            'otp' => $this->settings->get('show_ride_otp') === 'yes' ? ($ride['otp'] ?? null) : null,
            'booking_number' => $ride['booking_number'] ?? null,
            'amount' => $ride['montant'] ?? null,
            'driver' => $ride['driver'] ?? null,
            // The road the car is actually driving: driver → pickup while
            // heading to the rider, driver → destination once on ride. The
            // key changes only when the leg needs redrawing.
            'path' => $path,
            'path_key' => $pathKey,
        ]);
    }

    /**
     * The route leg the driver is on right now, re-routed only when the car
     * has moved ~110 m (3-decimal grid) so polling every few seconds doesn't
     * hammer the routing service.
     *
     * @return array{0: ?array, 1: ?string}
     */
    private function driverLeg(array $ride): array
    {
        $driver = $ride['driver'] ?? null;
        $status = $ride['statut'] ?? '';

        if (! is_array($driver) || empty($driver['latitude']) || ! in_array($status, ['confirmed', 'on ride'], true)) {
            return [null, null];
        }

        [$toLat, $toLng] = $status === 'confirmed'
            ? [(float) ($ride['latitude_depart'] ?? 0), (float) ($ride['longitude_depart'] ?? 0)]
            : [(float) ($ride['latitude_arrivee'] ?? 0), (float) ($ride['longitude_arrivee'] ?? 0)];

        if (! $toLat && ! $toLng) {
            return [null, null];
        }

        $fromLat = (float) $driver['latitude'];
        $fromLng = (float) $driver['longitude'];
        $pathKey = sprintf('%s:%.3f,%.3f', $status === 'confirmed' ? 'pickup' : 'drop', $fromLat, $fromLng);

        $path = \Illuminate\Support\Facades\Cache::remember(
            'cabme.ride.leg.'.($ride['id'] ?? 0).'.'.$pathKey,
            120,
            fn () => $this->geo->route($fromLat, $fromLng, $toLat, $toLng)['geometry'] ?? [],
        );

        return [$path ?: null, $pathKey];
    }

    public function cancel(Request $request, int $ride)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $result = $this->rides->cancel($ride, $data['reason'] ?? null);

        return $result->ok
            ? redirect()->route('app.ride.create')->with('status', __('Your ride was cancelled.'))
            : back()->withErrors(['cancel' => $result->messageOr(__('Could not cancel this ride.'))]);
    }

    /**
     * Change how an active ride will be paid. Nothing is charged here — the
     * driver's completion call settles cash/wallet — so any enabled method is
     * legal, exactly as in the app.
     */
    public function changePayment(Request $request, int $ride)
    {
        $data = $request->validate(['payment_method_id' => ['required', 'integer']]);

        $result = $this->rides->changePaymentMethod($ride, (int) $data['payment_method_id']);

        return $result->ok
            ? back()->with('status', __('Payment method updated.'))
            : back()->withErrors(['payment' => $result->messageOr(__('Could not change the payment method.'))]);
    }

    /** Sweeten the fare when nobody is accepting. */
    public function increaseFare(Request $request, int $ride)
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1']]);

        // The admin wants the new TOTAL alongside the bump, so read the
        // ride's current fare right now rather than trusting the page.
        $current = $this->rides->show($ride);

        if ($current->failed() || ! is_array($current->data)) {
            return back()->withErrors(['fare' => __('Could not load this ride. Please refresh.')]);
        }

        $bump = (float) $data['amount'];
        $newTotal = (float) ($current->data['montant'] ?? 0) + $bump;

        $result = $this->rides->increaseFare($ride, $newTotal, $bump);

        return $result->ok
            ? back()->with('status', 'Fare raised to '.$this->settings->money($newTotal).'. Re-notifying nearby drivers.')
            : back()->withErrors(['fare' => $result->messageOr(__('Could not update the fare.'))]);
    }
}
