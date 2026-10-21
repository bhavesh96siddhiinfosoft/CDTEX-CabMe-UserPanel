<?php

namespace App\Http\Controllers;

use App\Services\Payments\BookingCheckout;
use App\Services\Payments\GatewayCharge;
use Illuminate\Http\Request;

/**
 * Gateway charge for a pay-first booking (parcel, rental).
 *
 * The server decides the amount when the charge starts; the browser only
 * returns the gateway's identifiers. verify() leaves a consumed-on-use record
 * in the session which BookingCheckout::resolve() requires before the booking
 * may be placed. Which gateway runs is decided by the picked method's label —
 * GatewayCharge routes to Razorpay or Stripe.
 */
class PayController extends Controller
{
    // Public: RideController stashes ride charges under the same key so the
    // browser's shared /app/pay/verify polling works unchanged for rides.
    public const PENDING = 'booking_payment_pending';

    public function __construct(private readonly GatewayCharge $charge)
    {
    }

    public function createOrder(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:500000'],
            'payment_method_id' => ['required', 'integer'],
            'context' => ['required', 'in:parcel,rental'],
        ]);

        $result = $this->charge->start(
            $data['context'],
            (int) $data['payment_method_id'],
            round((float) $data['amount'], 2),
        );

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        $request->session()->put(self::PENDING, $result['pending']);

        return response()->json($result['client']);
    }

    public function verify(Request $request)
    {
        $pending = $request->session()->get(self::PENDING);

        if (! $pending) {
            return response()->json(['message' => __('That payment does not match a pending booking.')], 422);
        }

        $result = $this->charge->verify($request, $pending);

        // Gateways that settle asynchronously (hosted pages, MoMo approval,
        // PayFast's ITN) report "not yet" — keep the pending record and let
        // the browser poll, but only for so long: confirmations that exist
        // arrive within seconds, so ~80s of retries means it isn't coming.
        if (! $result['ok'] && ($result['retry'] ?? false)) {
            $attempts = (int) ($pending['verify_attempts'] ?? 0) + 1;

            if ($attempts < 20) {
                $pending['verify_attempts'] = $attempts;
                $request->session()->put(self::PENDING, $pending);

                return response()->json(['pending' => true], 202);
            }

            $result = ['ok' => false, 'error' => __('That payment has not completed.'), 'payment_id' => null];
        }

        $request->session()->forget(self::PENDING);

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        // BookingCheckout consumes this when the booking is placed.
        $request->session()->put(BookingCheckout::VERIFIED, [
            'transaction_id' => $result['payment_id'],
            'amount' => (float) $pending['amount'],
            'method_id' => (int) $pending['method_id'],
            'context' => $pending['context'],
        ]);

        return response()->json(['ok' => true]);
    }
}
