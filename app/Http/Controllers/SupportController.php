<?php

namespace App\Http\Controllers;

use App\Services\Cabme\SupportApi;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function __construct(private readonly SupportApi $support)
    {
    }

    public function index()
    {
        $complaints = $this->support->complaints();

        return view('app.support', [
            'complaints' => $this->paginate(
                $this->filterRows(
                    $complaints->ok ? $complaints->list() : [],
                    ['id', 'title', 'description', 'status', 'booking_id', 'booking_type'],
                    ['created_at'],
                ),
                route('app.support'),
            ),
        ]);
    }

    /**
     * Raise a complaint about one booking.
     *
     * The admin keys complaints to the booking and allows exactly one each, so
     * there is no free-form version of this — the rider opens the trip they
     * have a problem with and reports it from there.
     */
    public function store(Request $request, string $type, int $id)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        $result = $this->support->raiseComplaint($type, $id, $data['title'], $data['description']);

        return $result->ok
            ? back()->with('status', __('Your complaint has been submitted.'))
            : back()->withInput()->withErrors([
                'title' => $result->messageOr(__('Could not submit your complaint.')),
            ]);
    }

    /** Emergency alert raised from the live-tracking screen. */
    public function sos(Request $request)
    {
        // The admin identifies everything from the ride, so the ride id is
        // the one required field; coordinates are best-effort extra context.
        $data = $request->validate([
            'booking_id' => ['required', 'integer'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $result = $this->support->sos(
            (int) $data['booking_id'],
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
        );

        return response()->json([
            'ok' => $result->ok,
            'message' => $result->messageOr($result->ok ? __('Help is on the way.') : __('Could not send the alert.')),
        ], $result->ok ? 200 : 422);
    }
}
