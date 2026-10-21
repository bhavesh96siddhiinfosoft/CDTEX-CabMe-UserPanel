<?php

namespace App\Http\Controllers;

use App\Services\Cabme\BookingApi;
use App\Services\Cabme\ParcelApi;
use App\Services\Cabme\RentalApi;
use App\Services\Cabme\RideApi;
use App\Services\Cabme\SupportApi;
use Illuminate\Http\Request;

/** Unified history and detail across ride, parcel and rental. */
class TripController extends Controller
{
    public function __construct(
        private readonly BookingApi $bookings,
        private readonly RideApi $rides,
        private readonly ParcelApi $parcels,
        private readonly RentalApi $rentals,
        private readonly SupportApi $support,
    ) {
    }

    public function index(string $type = 'ride')
    {
        $result = $this->bookings->history($type);

        $trips = $this->filterRows(
            $result->ok ? $result->list() : [],
            ['id', 'booking_number', 'depart_name', 'destination_name', 'source', 'destination', 'source_adrs', 'destination_adrs'],
            ['creer', 'created_at'],
            ['montant', 'amount'],
        );

        return view('app.trips', [
            'type' => $type,
            'trips' => $this->paginate($trips, route('app.trips', $type)),
            // "No data found" is how the admin reports an empty list, so it
            // belongs in the empty state rather than an error banner.
            'error' => $result->failed() && ! $result->isEmptyResult()
                ? $result->messageOr(__('Could not load your trips.'))
                : null,
        ]);
    }

    public function show(string $type, int $id)
    {
        $result = match ($type) {
            'parcel' => $this->parcels->show($id),
            'rental' => $this->rentals->show($id),
            default => $this->rides->show($id),
        };

        abort_if($result->failed() || ! is_array($result->data), 404);

        $trip = $result->data;

        // Complaints only apply once the booking is over, so there is no point
        // asking the admin about one while the trip is still running.
        $done = in_array(strtolower((string) ($trip['statut'] ?? $trip['status'] ?? '')), ['completed', 'complete'], true);
        $complaint = $done ? $this->support->complaintFor($type, $id) : null;

        return view('app.trip-show', [
            'type' => $type,
            'trip' => $trip,
            'review' => $this->bookings->review($id, $type)->data,
            // "No complaint found" comes back as a failure envelope; that just
            // means the rider has not raised one.
            'complaint' => $complaint?->ok && is_array($complaint->data) ? $complaint->data : null,
        ]);
    }

    public function review(Request $request, string $type, int $id)
    {
        $data = $request->validate([
            'rating' => ['required', 'numeric', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        // A review belongs to the driver, not just the booking, and the
        // admin insists on the id. Read it from the booking rather than a
        // form field so it cannot be pointed at someone else's driver.
        $booking = match ($type) {
            'parcel' => $this->parcels->show($id),
            'rental' => $this->rentals->show($id),
            default => $this->rides->show($id),
        };

        $trip = is_array($booking->data) ? $booking->data : [];
        $driverId = (int) ($trip['id_conducteur'] ?? $trip['driver']['id'] ?? 0);

        if ($driverId <= 0) {
            return back()->withErrors(['rating' => __('This trip has no driver to rate.')]);
        }

        $result = $this->bookings->submitReview([
            'booking_id' => $id,
            'booking_type' => $type,
            'driver_id' => $driverId,
            'review_from' => 'customer',
            'review_to' => 'driver',
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? '',
        ]);

        return $result->ok
            ? back()->with('status', __('Thanks for the feedback.'))
            : back()->withErrors(['rating' => $result->messageOr(__('Could not submit your review.'))]);
    }
}
