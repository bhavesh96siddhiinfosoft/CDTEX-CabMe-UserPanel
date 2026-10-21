<?php

namespace App\Services\Payments;

use App\Services\Cabme\CatalogApi;
use App\Support\Rider;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Routes a charge to whichever gateway the picked method belongs to.
 *
 * One entry point for wallet top-ups and pay-first bookings, so adding a
 * gateway means: implement its driver, add its label fragment to
 * PaymentMethods::IMPLEMENTED_GATEWAYS, and add a branch here — the
 * controllers and the browser flow stay unchanged.
 */
class GatewayCharge
{
    public function __construct(
        private readonly CatalogApi $catalog,
        private readonly RazorpayGateway $razorpay,
        private readonly StripeGateway $stripe,
        private readonly PaystackGateway $paystack,
        private readonly FlutterwaveGateway $flutterwave,
        private readonly PaypalGateway $paypal,
        private readonly XenditGateway $xendit,
        private readonly MidtransGateway $midtrans,
        private readonly MercadopagoGateway $mercadopago,
        private readonly PayfastGateway $payfast,
        private readonly OrangePayGateway $orangepay,
        private readonly CashfreeGateway $cashfree,
        private readonly PhonepeGateway $phonepe,
        private readonly MtnMomoGateway $mtnmomo,
        private readonly InstamojoGateway $instamojo,
        private readonly PaymongoGateway $paymongo,
        private readonly FoloosiGateway $foloosi,
        private readonly Rider $rider,
    ) {
    }

    /** Which driver a payment-method label belongs to, or null. */
    public function gatewayFor(string $label): ?string
    {
        return \App\Support\PaymentMethods::driverKey($label);
    }

    /** The uniform-contract drivers; Razorpay and Stripe keep bespoke paths. */
    private function driver(string $gateway): ?object
    {
        return match ($gateway) {
            'paystack' => $this->paystack,
            'flutterwave' => $this->flutterwave,
            'paypal' => $this->paypal,
            'xendit' => $this->xendit,
            'midtrans' => $this->midtrans,
            'mercadopago' => $this->mercadopago,
            'payfast' => $this->payfast,
            'orangepay' => $this->orangepay,
            'cashfree' => $this->cashfree,
            'phonepe' => $this->phonepe,
            'mtnmomo' => $this->mtnmomo,
            'instamojo' => $this->instamojo,
            'paymongo' => $this->paymongo,
            'foloosi' => $this->foloosi,
            default => null,
        };
    }

    /**
     * Start a charge for a server-decided amount.
     *
     * @return array{ok:bool, error:?string, client:?array, pending:?array}
     *         `client` is what the browser needs to run the checkout;
     *         `pending` is what the caller must stash in the session for verify().
     */
    public function start(string $context, int $methodId, float $amount): array
    {
        $method = collect($this->catalog->enabledPaymentMethods($context))
            ->firstWhere('id', $methodId);

        $gateway = $method ? $this->gatewayFor($method['label']) : null;

        if (! $gateway) {
            return ['ok' => false, 'error' => __('That payment method is not available.'), 'client' => null, 'pending' => null];
        }

        $reference = strtolower($context).'_'.$this->rider->id().'_'.Str::lower(Str::random(10));

        if ($driver = $this->driver($gateway)) {
            if (! $driver->configured()) {
                return ['ok' => false, 'error' => __(':gateway is not configured in the admin panel.', ['gateway' => ucfirst($gateway)]), 'client' => null, 'pending' => null];
            }

            $started = $driver->start($amount, $reference, [
                'name' => $this->rider->name(),
                'email' => (string) $this->rider->get('email', ''),
                'phone' => (string) $this->rider->get('phone', ''),
            ]);

            // A driver may return a specific, user-actionable message
            // (gateway rejected the currency, profile missing a phone, …).
            if (isset($started['error'])) {
                return ['ok' => false, 'error' => $started['error'], 'client' => null, 'pending' => null];
            }

            if (! $started) {
                return ['ok' => false, 'error' => __('Could not start the payment. Please try again.'), 'client' => null, 'pending' => null];
            }

            return [
                'ok' => true,
                'error' => null,
                'client' => $started['client'],
                'pending' => $started['pending'] + [
                    'gateway' => $gateway,
                    'amount' => $amount,
                    'method_id' => $methodId,
                    'context' => $context,
                ],
            ];
        }

        if ($gateway === 'razorpay') {
            if (! $this->razorpay->configured()) {
                return ['ok' => false, 'error' => __(':gateway is not configured in the admin panel.', ['gateway' => 'Razorpay']), 'client' => null, 'pending' => null];
            }

            $order = $this->razorpay->createOrder($amount, $reference);

            if (! $order) {
                return ['ok' => false, 'error' => __('Could not start the payment. Please try again.'), 'client' => null, 'pending' => null];
            }

            return [
                'ok' => true,
                'error' => null,
                'client' => [
                    'gateway' => 'razorpay',
                    'key' => $this->razorpay->keyId(),
                    'order_id' => $order['id'],
                    'amount' => $order['amount'],
                    'currency' => $order['currency'],
                    'name' => config('app.name'),
                    'prefill' => [
                        'name' => $this->rider->name(),
                        'email' => (string) $this->rider->get('email', ''),
                        'contact' => (string) $this->rider->get('phone', ''),
                    ],
                ],
                'pending' => [
                    'gateway' => 'razorpay',
                    'order_id' => $order['id'],
                    'amount' => $amount,
                    'minor' => $order['amount'],
                    'method_id' => $methodId,
                    'context' => $context,
                ],
            ];
        }

        // Stripe
        if (! $this->stripe->configured()) {
            return ['ok' => false, 'error' => __(':gateway is not configured in the admin panel.', ['gateway' => 'Stripe']), 'client' => null, 'pending' => null];
        }

        $intent = $this->stripe->createIntent($amount, $reference);

        if (! $intent) {
            return ['ok' => false, 'error' => __('Could not start the payment. Please try again.'), 'client' => null, 'pending' => null];
        }

        return [
            'ok' => true,
            'error' => null,
            'client' => [
                'gateway' => 'stripe',
                'publishable_key' => $this->stripe->publishableKey(),
                'client_secret' => $intent['client_secret'],
                'amount' => $intent['amount'],
                'currency' => $intent['currency'],
            ],
            'pending' => [
                'gateway' => 'stripe',
                'intent_id' => $intent['id'],
                'reference' => $reference,
                'amount' => $amount,
                'minor' => $intent['amount'],
                'method_id' => $methodId,
                'context' => $context,
            ],
        ];
    }

    /**
     * Verify the browser's callback against the pending record.
     *
     * @return array{ok:bool, error:?string, payment_id:?string}
     */
    public function verify(Request $request, array $pending): array
    {
        if ($driver = $this->driver((string) ($pending['gateway'] ?? ''))) {
            return $driver->verify($request, $pending);
        }

        if (($pending['gateway'] ?? '') === 'stripe') {
            $data = $request->validate([
                'payment_intent_id' => ['required', 'string', 'max:191'],
            ]);

            // The intent id must be OURS — the browser cannot substitute a
            // different (cheaper) intent it paid separately.
            if ($data['payment_intent_id'] !== $pending['intent_id']) {
                return ['ok' => false, 'error' => __('That payment does not match this order.'), 'payment_id' => null];
            }

            return $this->stripe->verify($pending['intent_id'], (int) $pending['minor'], $pending['reference']);
        }

        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:191'],
            'razorpay_payment_id' => ['required', 'string', 'max:191'],
            'razorpay_signature' => ['required', 'string', 'max:255'],
        ]);

        if ($data['razorpay_order_id'] !== ($pending['order_id'] ?? '')) {
            return ['ok' => false, 'error' => __('That payment does not match this order.'), 'payment_id' => null];
        }

        return $this->razorpay->verify(
            $data['razorpay_order_id'],
            $data['razorpay_payment_id'],
            $data['razorpay_signature'],
            (int) $pending['minor'],
        );
    }
}
