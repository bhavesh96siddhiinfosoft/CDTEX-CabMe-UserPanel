<?php

namespace App\Http\Controllers;

use App\Services\Cabme\CatalogApi;
use App\Services\Cabme\ParcelApi;
use App\Services\GeoService;
use App\Support\CabmeSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ParcelController extends Controller
{
    public function __construct(
        private readonly ParcelApi $parcels,
        private readonly CatalogApi $catalog,
        private readonly GeoService $geo,
        private readonly CabmeSettings $settings,
    ) {
    }

    public function create()
    {
        abort_unless($this->settings->serviceEnabled('parcel'), 404);

        return view('parcel.create', [
            'categories' => $this->catalog->parcelCategories()->list(),
            'paymentMethods' => $this->catalog->enabledPaymentMethods('parcel'),
            'coupons' => $this->catalog->coupons('parcel')->list(),
        ]);
    }

    /**
     * Price a delivery. Unlike a ride, a parcel's fare depends on its WEIGHT
     * as well as the distance — the admin charges per km plus per kg — so the
     * weight has to travel with the quote or every delivery is priced as if
     * it were weightless.
     */
    public function quote(Request $request)
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric'],
            'pickup_lng' => ['required', 'numeric'],
            'drop_lat' => ['required', 'numeric'],
            'drop_lng' => ['required', 'numeric'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        $route = $this->geo->route($data['pickup_lat'], $data['pickup_lng'], $data['drop_lat'], $data['drop_lng'])
            ?? [
                'distance' => $this->geo->haversine($data['pickup_lat'], $data['pickup_lng'], $data['drop_lat'], $data['drop_lng']),
                'duration' => null,
                'unit' => $this->settings->distanceUnit(),
                'estimated' => true,
            ];

        $result = $this->catalog->parcelCategoriesWithFare($data + [
            'distance' => $route['distance'],
            'weight' => $data['weight'] ?? 0,
        ]);

        if ($result->failed()) {
            return response()->json(['message' => $result->messageOr(__('Could not price this delivery.'))], 422);
        }

        return response()->json([
            'route' => $route,
            'categories' => $result->list(),
            // The same rows the admin will store on the booking, so the
            // billing breakdown shows what will actually be charged.
            'taxes' => $this->taxesFor((float) $data['pickup_lat'], (float) $data['pickup_lng']),
        ]);
    }

    /** @return array<int, array{libelle:string, value:float, type:string}> */
    private function taxesFor(float $lat, float $lng): array
    {
        return app(\App\Support\Taxes::class)->forCountry($this->geo->country($lat, $lng));
    }

    /**
     * What the rider actually pays: (subtotal − coupon) + taxes, the same
     * order the admin's completion call uses. The coupon's value is read from
     * the admin rather than the form, so only the id has to be trusted.
     *
     * @return array{subtotal:float, discount:float, tax:float, total:float}
     */
    private function billing(float $subtotal, ?int $couponId, float $lat, float $lng): array
    {
        $discount = 0.0;

        if ($couponId) {
            $coupon = collect($this->catalog->coupons('parcel')->list())
                ->first(fn ($row) => (int) ($row['id'] ?? 0) === $couponId);

            if ($coupon) {
                $discount = strtolower((string) ($coupon['type'] ?? '')) === 'percentage'
                    ? (float) ($coupon['discount'] ?? 0) * $subtotal / 100
                    : (float) ($coupon['discount'] ?? 0);
            }
        }

        $discount = min(round($discount, 2), $subtotal);
        $base = round($subtotal - $discount, 2);
        $tax = app(\App\Support\Taxes::class)->on($base, $this->taxesFor($lat, $lng));

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => $discount,
            'tax' => $tax,
            'total' => round($base + $tax, 2),
        ];
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
            'distance' => ['required', 'numeric', 'min:0'],
            'category_id' => ['required', 'integer'],
            'payment_method_id' => ['required', 'integer'],
            'sender_name' => ['required', 'string', 'max:255'],
            'sender_phone' => ['required', 'string', 'max:20'],
            'receiver_name' => ['required', 'string', 'max:255'],
            'receiver_phone' => ['required', 'string', 'max:20'],
            'weight' => ['required', 'numeric', 'min:0'],
            'dimension' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'pickup_date' => ['required', 'date'],
            'pickup_time' => ['required', 'string'],
            'receive_date' => ['required', 'date', 'after_or_equal:pickup_date'],
            'receive_time' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
            'coupon_id' => ['nullable', 'integer'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'max:5120'],
        ]);

        $data['distance_unit'] = $this->settings->distanceUnit();

        // `amount` is the SUBTOTAL, as the app posts it: the admin stores it
        // with the coupon and its own tax snapshot, then bills
        // (amount − discount) + tax at completion. What the rider pays now is
        // that same total, worked out here rather than taken from the form.
        $billing = $this->billing(
            (float) $data['amount'],
            $data['coupon_id'] ?? null,
            (float) $data['pickup_lat'],
            (float) $data['pickup_lng'],
        );

        // Cash books directly; wallet requires the balance to cover the fare
        // (the ADMIN debits it at register); a gateway method requires a
        // server-verified charge for this exact amount. Never browser-supplied.
        $payment = app(\App\Services\Payments\BookingCheckout::class)
            ->resolve('parcel', (int) $data['payment_method_id'], $billing['total']);

        if (! $payment['ok']) {
            return back()->withInput()->withErrors(['booking' => $payment['error']]);
        }

        $data['transaction_id'] = $payment['transaction_id'];

        // ParcelRegisterController reads `parcel_image`, not the form's own
        // field name — attaching them as images[] meant the admin never saw
        // a file and stored the placeholder instead.
        $images = [];
        foreach ((array) $request->file('images') as $i => $file) {
            $images["parcel_image[$i]"] = $file;
        }

        $result = $this->parcels->book($data, $images);

        if ($result->failed()) {
            return back()->withInput()
                ->withErrors(['booking' => $result->messageOr(__('Could not place your delivery.'))]);
        }

        return redirect()->route('app.trips', 'parcel')
            ->with('status', __('Delivery booked. Searching for a driver.'));
    }

    /** Live tracking for a parcel in progress — same shell as rides. */
    public function track(int $parcel)
    {
        $result = $this->parcels->show($parcel);

        abort_if($result->failed() || ! is_array($result->data), 404);

        $order = $result->data;

        [$path, $pathKey] = $this->driverLeg($order);

        // No driver yet: show the delivery itself, sender to receiver.
        if (! $path && ! empty($order['lat_source']) && ! empty($order['lat_destination'])) {
            $route = $this->geo->route(
                (float) $order['lat_source'],
                (float) $order['lng_source'],
                (float) $order['lat_destination'],
                (float) $order['lng_destination'],
            );
            $path = $route['geometry'] ?? null;
        }

        return view('parcel.track', [
            'order' => $order,
            'path' => $path,
            'pathKey' => $pathKey,
            'pollInterval' => config('cabme.poll_interval'),
        ]);
    }

    /**
     * The leg the courier is actually driving.
     *
     * Before pickup that is driver → sender; once the parcel is aboard it is
     * driver → receiver. Cached briefly and keyed on the rounded driver
     * position so the tracker only re-routes when the courier has really moved.
     *
     * @return array{0: ?array, 1: ?string}  [path, cache key]
     */
    private function driverLeg(array $order): array
    {
        $driver = $order['driver'] ?? null;
        $status = strtolower((string) ($order['status'] ?? $order['statut'] ?? ''));

        if (! is_array($driver) || empty($driver['latitude']) || ! in_array($status, ['confirmed', 'on ride'], true)) {
            return [null, null];
        }

        // 'confirmed' = on the way to collect; 'on ride' = carrying it.
        [$toLat, $toLng] = $status === 'confirmed'
            ? [(float) ($order['lat_source'] ?? 0), (float) ($order['lng_source'] ?? 0)]
            : [(float) ($order['lat_destination'] ?? 0), (float) ($order['lng_destination'] ?? 0)];

        if (! $toLat && ! $toLng) {
            return [null, null];
        }

        $fromLat = (float) $driver['latitude'];
        $fromLng = (float) $driver['longitude'];
        $pathKey = sprintf('%s:%.3f,%.3f', $status === 'confirmed' ? 'sender' : 'receiver', $fromLat, $fromLng);

        $path = \Illuminate\Support\Facades\Cache::remember(
            'cabme.parcel.leg.'.($order['id'] ?? 0).'.'.$pathKey,
            120,
            fn () => $this->geo->route($fromLat, $fromLng, $toLat, $toLng)['geometry'] ?? [],
        );

        return [$path ?: null, $pathKey];
    }

    /** Polled by the tracking page — driver position rides on get-parcel-detail. */
    public function status(int $parcel)
    {
        $result = $this->parcels->show($parcel);

        if ($result->failed() || ! is_array($result->data)) {
            return response()->json(['active' => false]);
        }

        $order = $result->data;
        // parcel_orders uses `status` (rides use `statut`) — accept both.
        $status = strtolower((string) ($order['status'] ?? $order['statut'] ?? ''));

        [$path, $pathKey] = $this->driverLeg($order);

        return response()->json([
            'active' => ! in_array($status, ['completed', 'canceled', 'cancelled', 'rejected'], true),
            'status' => $order['status'] ?? $order['statut'] ?? null,
            'driver' => $order['driver'] ?? null,
            // Driver → sender while collecting, driver → receiver once the
            // parcel is aboard. The key changes only when a redraw is due.
            'path' => $path,
            'path_key' => $pathKey,
        ]);
    }

    public function cancel(Request $request, int $parcel)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $result = $this->parcels->cancel($parcel, $data['reason'] ?? null);

        return $result->ok
            // Back to the booking screen, as a cancelled ride does: the
            // rider is most likely to want another one.
            ? redirect()->route('app.parcel.create')->with('status', __('Delivery cancelled.'))
            : back()->withErrors(['cancel' => $result->messageOr(__('Could not cancel this delivery.'))]);
    }
}
