<?php

namespace App\Services\Cabme;

use App\Support\ApiResult;
use App\Support\Rider;

/** Unified trip history across all three services. */
class BookingApi
{
    public function __construct(
        private readonly CabmeClient $client,
        private readonly Rider $rider,
    ) {
    }

    /** $type is one of ride, parcel, rental. */
    public function history(string $type): ApiResult
    {
        return $this->client->auth('POST', 'v1/get-booking-list/', [
            'user_id' => $this->rider->id(),
            'user_type' => 'customer',
            'booking_type' => $type,
        ]);
    }

    public function submitReview(array $review): ApiResult
    {
        return $this->client->auth('POST', 'v1/submit-review/', array_merge($review, [
            'user_id' => $this->rider->id(),
            'user_type' => 'customer',
        ]));
    }

    /**
     * The rider's own review of a booking.
     *
     * `review_from` is required by the admin: reviews exist in both
     * directions and without it the lookup is rejected outright, which is
     * why an existing rating never showed and the form kept reappearing.
     */
    public function review(int $bookingId, string $type = 'ride'): ApiResult
    {
        return $this->client->auth('POST', 'v1/get-review/', [
            'booking_id' => $bookingId,
            'booking_type' => $type,
            'review_from' => 'customer',
        ]);
    }
}
