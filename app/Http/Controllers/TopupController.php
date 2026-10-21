<?php

namespace App\Http\Controllers;

use App\Services\Cabme\AuthApi;
use App\Services\Cabme\WalletApi;
use App\Services\Payments\GatewayCharge;
use App\Support\Rider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Wallet top-up through a real gateway (Razorpay or Stripe, routed by the
 * picked method). The amount is decided here and remembered in the session;
 * the browser only ever hands back gateway identifiers. Verification must
 * pass — signature/status and amount — before a single unit is credited.
 */
class TopupController extends Controller
{
    private const PENDING = 'topup_payment_pending';

    public function __construct(
        private readonly GatewayCharge $charge,
        private readonly WalletApi $wallet,
        private readonly AuthApi $auth,
        private readonly Rider $rider,
    ) {
    }

    /** Step 1 — start the charge for a server-decided amount. */
    public function createOrder(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'payment_method_id' => ['required', 'integer'],
        ]);

        $result = $this->charge->start('topup', (int) $data['payment_method_id'], round((float) $data['amount'], 2));

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        $request->session()->put(self::PENDING, $result['pending']);

        return response()->json($result['client']);
    }

    /** Step 2 — verify with the gateway, then credit. */
    public function verify(Request $request)
    {
        $pending = $request->session()->get(self::PENDING);

        if (! $pending) {
            return response()->json(['message' => __('That payment does not match a pending top-up.')], 422);
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

        // Consume the pending record either way, so a captured payment can
        // never be replayed against a second credit.
        $request->session()->forget(self::PENDING);

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        // Only now does money move, and with OUR amount. The admin stores the
        // method's own name per transaction, which the history list shows.
        $credit = $this->wallet->credit(
            (float) $pending['amount'],
            (int) $pending['method_id'],
            $result['payment_id'],
            __('Wallet top-up'),
        );

        if ($credit->failed()) {
            // The admin credits before sending its confirmation email and a
            // mail failure surfaces as an error, so confirm against the balance
            // rather than stranding a rider who has genuinely paid.
            $before = $this->rider->balance();
            $this->auth->refreshProfile();

            if ($this->rider->balance() <= $before) {
                Log::error('Payment captured but wallet credit failed', [
                    'payment_id' => $result['payment_id'],
                    'rider' => $this->rider->id(),
                    'amount' => $pending['amount'],
                    'error' => $credit->message,
                ]);

                return response()->json([
                    'message' => 'Your payment went through but the wallet could not be credited. Please contact support with reference '.$result['payment_id'].'.',
                ], 500);
            }
        }

        return response()->json(['ok' => true, 'redirect' => route('app.wallet')]);
    }
}
