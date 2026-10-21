<?php

namespace App\Http\Controllers;

use App\Services\Cabme\CatalogApi;
use App\Services\Cabme\RentalApi;
use App\Support\CabmeSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RentalController extends Controller
{
    public function __construct(
        private readonly RentalApi $rentals,
        private readonly CatalogApi $catalog,
        private readonly CabmeSettings $settings,
    ) {
    }

    public function create()
    {
        abort_unless($this->settings->serviceEnabled('rental'), 404);

        return view('rental.create', [
            'packages' => $this->catalog->rentalPackages()->list(),
            'vehicleTypes' => $this->catalog->vehicleTypes()->list(),
            'paymentMethods' => $this->catalog->enabledPaymentMethods('rental'),
            'coupons' => $this->catalog->coupons('rental')->list(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric'],
            'pickup_lng' => ['required', 'numeric'],
            'pickup_address' => ['required', 'string', 'max:500'],
            'package_id' => ['required', 'integer'],
            'vehicle_type_id' => ['required', 'integer'],
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0'],
            'coupon_id' => ['nullable', 'integer'],
            'start_date' => ['required', 'date'],
            'start_time' => ['required', 'string'],
        ]);

        // Nothing is charged at booking. A rental's real cost is not known
        // yet: the driver's final-km call adds the extra distance and time,
        // rewrites the order's amount, and only then does the rider pay —
        // which is also why no end time is asked for here. The admin still
        // wants an end on the row, so the package's own included hours give
        // it a sensible expected one until the driver files the real figure.
        $package = collect($this->catalog->rentalPackages()->list())
            ->first(fn ($row) => (int) ($row['id'] ?? 0) === (int) $data['package_id']);

        $start = \Illuminate\Support\Carbon::parse($data['start_date'].' '.$data['start_time']);
        $end = $start->copy()->addHours((int) ($package['includedHours'] ?? 1));

        $data['end_date'] = $end->toDateString();
        $data['end_time'] = $end->format('H:i');

        // The admin requires a transaction id on the row; the real one
        // arrives with the payment at the end of the rental.
        $data['transaction_id'] = 'RENTAL-'.Str::upper(Str::random(12));

        $result = $this->rentals->book($data);

        if ($result->failed()) {
            return back()->withInput()
                ->withErrors(['booking' => $result->messageOr(__('Could not place your rental.'))]);
        }

        // rental-register returns the created order; land on its live view.
        $rentalId = (int) ($result->data['id'] ?? 0);

        return $rentalId > 0
            ? redirect()->route('app.rental.track', $rentalId)->with('status', __('Rental booked.'))
            : redirect()->route('app.trips', 'rental')->with('status', __('Rental booked.'));
    }

    /** Record the payment method without settling — the cash path. */
    public function changePayment(Request $request, int $rental)
    {
        $data = $request->validate(['payment_method_id' => ['required', 'integer']]);

        $result = $this->rentals->changePaymentMethod($rental, (int) $data['payment_method_id']);

        return $result->ok
            ? back()->with('status', __('Cash selected. Pay your driver — they will confirm it and end the rental.'))
            : back()->withErrors(['pay' => $result->messageOr(__('Could not change the payment method.'))]);
    }

    /** Live status for a rental in progress — same shell as rides/parcels. */
    public function track(int $rental)
    {
        $order = $this->ownOrder($rental);

        return view('rental.track', [
            'order' => $order,
            // Rentals use the ride flow's switch; when it is off the code
            // never reaches the browser at all.
            'otp' => $this->settings->get('show_ride_otp') === 'yes' ? ($order['otp'] ?? null) : null,
            'pollInterval' => config('cabme.poll_interval'),
            // Payable once the driver has filed the final km; until then the
            // amount on the order is only the package's base fare.
            'payable' => $this->payable($order),
            'otp' => $this->settings->get('show_ride_otp') === 'yes' ? ($order['otp'] ?? null) : null,
            'due' => $this->totalDue($order),
            'paymentMethods' => $this->catalog->enabledPaymentMethods('rental'),
        ]);
    }

    /**
     * A rental can be paid once the driver has entered the final km — that
     * call adds the extra distance and time and rewrites the order's amount,
     * so before it there is nothing final to charge.
     */
    private function payable(array $order): bool
    {
        return (float) ($order['complete_km'] ?? 0) > 0
            && strtolower((string) ($order['status'] ?? '')) === 'on ride';
    }

    /** @return array{amount:float, discount:float, tax:float, total:float} */
    private function totalDue(array $order): array
    {
        return \App\Support\BookingBill::due(
            (float) ($order['amount'] ?? 0),
            $order['discount_type'] ?? null,
            $order['tax'] ?? null,
        );
    }

    /** The rider's own rental, only while it can actually be paid. */
    private function payableOrder(int $rental): ?array
    {
        $result = $this->rentals->show($rental);
        $order = $result->ok && is_array($result->data) ? $result->data : null;

        if (! $order || (int) ($order['id_user_app'] ?? 0) !== (int) app(\App\Support\Rider::class)->id()) {
            return null;
        }

        return $this->payable($order) ? $order : null;
    }

    /**
     * Start a gateway charge for the final rental total. The amount is decided
     * HERE from the order the admin holds; the browser only names the method.
     */
    public function payOrder(Request $request, int $rental)
    {
        $data = $request->validate(['payment_method_id' => ['required', 'integer']]);

        $order = $this->payableOrder($rental);

        if (! $order) {
            return response()->json(['message' => __('This rental cannot be paid yet.')], 422);
        }

        $charge = app(\App\Services\Payments\GatewayCharge::class)
            ->start('rental', (int) $data['payment_method_id'], $this->totalDue($order)['total']);

        if (! $charge['ok']) {
            return response()->json(['message' => $charge['error']], 422);
        }

        $request->session()->put(PayController::PENDING, $charge['pending']);

        return response()->json($charge['client']);
    }

    /**
     * Pay and close the rental — the app's rental payment screen. Cash and
     * wallet settle inside the admin's completion call; a gateway method must
     * already have a server-verified charge waiting in the session.
     */
    public function complete(Request $request, int $rental)
    {
        $data = $request->validate(['payment_method_id' => ['required', 'integer']]);

        $order = $this->payableOrder($rental);

        if (! $order) {
            return back()->withErrors(['pay' => __('This rental cannot be paid yet.')]);
        }

        // Cash never settles a rental from here: the rider hands the money
        // over and the DRIVER closes it, the same rule as a ride. Enforced
        // server-side so a posted form cannot bypass the button.
        $method = collect($this->catalog->enabledPaymentMethods('rental'))
            ->firstWhere('id', (int) $data['payment_method_id']);

        if (($method['kind'] ?? null) === 'offline') {
            return $this->changePayment($request, $rental);
        }

        $payment = app(\App\Services\Payments\BookingCheckout::class)
            ->resolve('rental', (int) $data['payment_method_id'], $this->totalDue($order)['total']);

        if (! $payment['ok']) {
            return back()->withErrors(['pay' => $payment['error']]);
        }

        $done = $this->rentals->complete($order, (int) $data['payment_method_id'], $payment['transaction_id']);

        if ($done->failed()) {
            return back()->withErrors(['pay' => $done->messageOr(__('Could not complete the payment. Please try again.'))]);
        }

        return redirect()->route('app.trips.show', ['rental', $rental])
            ->with('status', __('Payment successful — thanks for renting with us!'));
    }

    /** Polled by the tracking page — driver position rides on the details call. */
    public function status(int $rental)
    {
        $result = $this->rentals->show($rental);

        if ($result->failed() || ! is_array($result->data)) {
            return response()->json(['active' => false]);
        }

        $order = $result->data;

        if ((int) ($order['id_user_app'] ?? 0) !== (int) app(\App\Support\Rider::class)->id()) {
            return response()->json(['active' => false]);
        }

        $status = strtolower((string) ($order['status'] ?? ''));

        return response()->json([
            'active' => ! in_array($status, ['completed', 'canceled', 'cancelled'], true),
            'status' => $order['status'] ?? null,
            'driver' => $order['driver'] ?? null,
            // The tracker reloads on this: the driver filing the final km is
            // what turns the screen into a payment screen.
            'payable' => $this->payable($order),
            'otp' => $this->settings->get('show_ride_otp') === 'yes' ? ($order['otp'] ?? null) : null,
        ]);
    }

    /**
     * Fetch the rental and make sure it belongs to the signed-in rider — the
     * admin's details endpoint itself takes any id.
     */
    private function ownOrder(int $rental): array
    {
        $result = $this->rentals->show($rental);

        abort_if($result->failed() || ! is_array($result->data), 404);

        $order = $result->data;

        abort_if((int) ($order['id_user_app'] ?? 0) !== (int) app(\App\Support\Rider::class)->id(), 404);

        return $order;
    }

    public function cancel(Request $request, int $rental)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $result = $this->rentals->cancel($rental, $data['reason'] ?? null);

        return $result->ok
            // Back to the booking screen, as a cancelled ride does: the
            // rider is most likely to want another one.
            ? redirect()->route('app.rental.create')->with('status', __('Rental cancelled.'))
            : back()->withErrors(['cancel' => $result->messageOr(__('Could not cancel this rental.'))]);
    }
}
