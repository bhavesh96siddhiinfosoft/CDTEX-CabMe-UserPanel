<?php

namespace App\Http\Controllers;

use App\Services\Cabme\CatalogApi;
use App\Services\Cabme\RideApi;
use App\Support\Rider;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function __construct(private readonly CatalogApi $catalog)
    {
    }

    public function home(Rider $rider)
    {
        // Signed-in riders have no use for the marketing page.
        if ($rider->check()) {
            return redirect()->route('app.home');
        }
        
        return view('pages.home', [
            // Shown once a route is set — options first, sign-in to price.
            'vehicleTypes' => $this->catalog->vehicleTypes()->list(),
        ]);
    }

    /**
     * Stash the trip a guest composed on the landing page and send them to sign
     * in. Fares need a rider token, so pricing can only happen after that.
     */
    public function start(Request $request)
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric'],
            'pickup_lng' => ['required', 'numeric'],
            'drop_lat' => ['required', 'numeric'],
            'drop_lng' => ['required', 'numeric'],
            'pickup_address' => ['nullable', 'string', 'max:500'],
            'drop_address' => ['nullable', 'string', 'max:500'],
        ]);

        $request->session()->put('pending_route', $data);

        return redirect()->route('login')
            ->with('intended', route('app.ride.create'))
            ->with('status', __('Sign in to see fares and book this trip.'));
    }

    public function services()
    {
        return view('pages.services');
    }

    /** Switch the interface language — only codes the admin enables count. */
    public function setLocale(Request $request)
    {
        $data = $request->validate(['locale' => ['required', 'string', 'regex:/^[a-z]{2}$/']]);

        $codes = array_column($this->catalog->languages(), 'code');

        if (in_array($data['locale'], $codes, true)) {
            $request->session()->put('locale', $data['locale']);
        }

        return back();
    }

    public function coverage()
    {
        return view('pages.coverage', ['zones' => $this->catalog->zones()->list()]);
    }

    /** Rider landing: banners, the active ride if any, and quick actions. */
    public function dashboard(RideApi $rides, \App\Services\Cabme\AuthApi $auth)
    {
        // The hero shows the wallet balance — read it fresh, not the login snapshot.
        $auth->refreshProfile();

        $active = $rides->active();

        return view('app.dashboard', [
            'banners' => $this->catalog->banners()->list(),
            'activeRide' => $active->ok && is_array($active->data) ? $active->data : null,
        ]);
    }
}
