<?php

namespace App\Services\Cabme;

use App\Support\ApiResult;
use App\Support\Rider;

/** Cab ride lifecycle. */
class RideApi
{
    public function __construct(
        private readonly CabmeClient $client,
        private readonly Rider $rider,
    ) {
    }

    /**
     * Place a booking. The admin refuses if the rider already has a ride in
     * new/confirmed/on-ride, and searches for a driver asynchronously, so a
     * success here means "booking created", not "driver assigned".
     */
    public function book(array $trip): ApiResult
    {
        return $this->client->auth('POST', 'v1/ride-book/', [
            'user_id' => $this->rider->id(),
            'latitude_depart' => $trip['pickup_lat'],
            'longitude_depart' => $trip['pickup_lng'],
            'latitude_arrivee' => $trip['drop_lat'],
            'longitude_arrivee' => $trip['drop_lng'],
            'depart_name' => $trip['pickup_address'],
            'destination_name' => $trip['drop_address'],
            'total_people' => $trip['passengers'] ?? 1,
            'total_children' => $trip['children'] ?? 0,
            'vehicle_type_id' => $trip['vehicle_type_id'] ?? null,
            'distance' => $trip['distance'],
            'duration' => $trip['duration'],
            'sub_total' => $trip['sub_total'],
            'surge_multiplier' => $trip['surge_multiplier'] ?? 1,
            'id_payment' => $trip['payment_method_id'] ?? null,
            'discount_id' => $trip['coupon_id'] ?? null,
            'stops' => $this->encodeStops($trip['stops'] ?? null),
        ]);
    }

    /**
     * Stops, encoded the way the rest of this payload is.
     *
     * The apps write stops as {latitude, longitude, location} with the
     * coordinates as STRINGS — verified against rides booked from the app
     * (1053, 1048, 1028). This panel had been writing {..., address} with
     * float coordinates, so a client reading `location` got null and a
     * client parsing coordinates as text got a number: the ride list never
     * finished loading for any ride booked here that had stops.
     */
    private function encodeStops(?array $stops): ?string
    {
        $clean = [];

        foreach ($stops ?? [] as $stop) {
            if (! is_array($stop) || ! isset($stop['latitude'], $stop['longitude'])) {
                continue;
            }

            $clean[] = [
                'latitude' => (string) $stop['latitude'],
                'longitude' => (string) $stop['longitude'],
                // The apps read this key; ours is called address internally.
                'location' => (string) ($stop['location'] ?? $stop['address'] ?? ''),
            ];
        }

        return $clean ? json_encode($clean) : null;
    }

    /** The rider's current ride, if any — the source for live tracking. */
    public function active(): ApiResult
    {
        return $this->client->auth('POST', 'v1/user-recent-ride/', [
            'id_user' => $this->rider->id(),
        ]);
    }

    public function show(int $rideId): ApiResult
    {
        return $this->client->auth('POST', 'v1/get-booking-details/', ['id_ride' => $rideId]);
    }

    /**
     * Raise the offered fare when no driver is accepting.
     *
     * The admin wants the NEW TOTAL and the bump separately —
     * RideIncreaseFareController validates id_ride/id_user/new_amount/
     * bump_amount and re-notifies drivers with the fresh figure.
     */
    public function increaseFare(int $rideId, float $newTotal, float $bump): ApiResult
    {
        return $this->client->auth('POST', 'v1/ride-increase-fare/', [
            'id_ride' => $rideId,
            'id_user' => $this->rider->id(),
            'new_amount' => $newTotal,
            'bump_amount' => $bump,
        ]);
    }

    public function cancel(int $rideId, ?string $reason = null): ApiResult
    {
        // RideRejectController::cancelledRequest validates id_ride + id_user
        // (not user_id — that name belongs to other endpoints).
        return $this->client->auth('POST', 'v1/set-cancelled-requete/', [
            'id_ride' => $rideId,
            'id_user' => $this->rider->id(),
            'reason' => $reason,
        ]);
    }

    /**
     * Settle and finish the ride — in this product the CUSTOMER completes the
     * ride by paying (the app's Pay Now screen posts complete-requete). The
     * admin recomputes discount and taxes server-side, debits the wallet when
     * that method is picked, and credits the driver.
     */
    public function complete(array $ride, int $paymentMethodId, string $transactionId): ApiResult
    {
        return $this->client->auth('POST', 'v1/complete-requete/', [
            'id_ride' => $ride['id'],
            'id_user' => $this->rider->id(),
            'id_driver' => $ride['id_conducteur'],
            'id_payment' => $paymentMethodId,
            'transaction_id' => $transactionId,
            // Mirrors the app: discount is recomputed by the admin from the
            // booking's own discount_type; tip is required but web sends none.
            'discount' => '0',
            'tip' => '0',
        ]);
    }

    public function changePaymentMethod(int $rideId, int $paymentMethodId): ApiResult
    {
        // ChangeStatusForpaymentController validates booking_type/booking_id/
        // id_payment — not the id_ride/id_payment_method names used elsewhere.
        return $this->client->auth('POST', 'v1/change-booking-payment-method/', [
            'booking_type' => 'ride',
            'booking_id' => $rideId,
            'id_payment' => $paymentMethodId,
        ]);
    }
}
