<?php

namespace App\Services\Cabme;

use App\Support\ApiResult;
use App\Support\Rider;

/** Complaints, SOS and the CMS content pages. */
class SupportApi
{
    public function __construct(
        private readonly CabmeClient $client,
        private readonly Rider $rider,
    ) {
    }

    /**
     * Every complaint the rider has raised, newest first.
     *
     * A complaint belongs to a booking, not to an account — the admin works
     * out ownership from the bookings themselves.
     */
    public function complaints(): ApiResult
    {
        return $this->client->auth('POST', 'v1/complaintsList/', [
            'user_id' => $this->rider->id(),
            'user_type' => 'customer',
        ]);
    }

    /**
     * The complaint raised on one booking, if there is one.
     *
     * The admin answers "no complaint found" with a failure envelope, so an
     * empty result here is the normal case, not an error.
     */
    public function complaintFor(string $bookingType, int $bookingId): ApiResult
    {
        return $this->client->auth('POST', 'v1/complaintsList/', [
            'booking_type' => $bookingType,
            'booking_id' => $bookingId,
        ]);
    }

    /**
     * Raise a complaint against a booking. The admin allows exactly one per
     * booking and starts it at `initiated`.
     */
    public function raiseComplaint(string $bookingType, int $bookingId, string $title, string $description): ApiResult
    {
        return $this->client->auth('POST', 'v1/complaints/', [
            'booking_type' => $bookingType,
            'booking_id' => $bookingId,
            'title' => $title,
            'description' => $description,
        ]);
    }

    /**
     * Emergency alert. SosController validates exactly one field: ride_id
     * (must exist in requete) — it loads the rider from the ride itself.
     */
    public function sos(int $rideId, ?float $latitude = null, ?float $longitude = null): ApiResult
    {
        return $this->client->auth('POST', 'v1/storesos/', [
            'ride_id' => $rideId,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    public function privacyPolicy(): ApiResult
    {
        return $this->client->guest('GET', 'v1/privacy-policy/');
    }

    public function terms(): ApiResult
    {
        return $this->client->guest('GET', 'v1/terms-of-condition/');
    }

    /** Published CMS pages from the admin — names and slugs, no bodies. */
    public function cmsPages(): ApiResult
    {
        return $this->client->guest('GET', 'v1/cms-pages/');
    }

    /** One published CMS page, body included. */
    public function cmsPage(string $slug): ApiResult
    {
        return $this->client->guest('GET', 'v1/cms-page/'.rawurlencode($slug));
    }

    public function onBoarding(): ApiResult
    {
        return $this->client->guest('GET', 'v1/on-boarding/');
    }
}
