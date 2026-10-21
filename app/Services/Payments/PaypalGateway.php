<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayPal via the Orders v2 API.
 *
 * The server creates the order for ITS amount; the browser renders the
 * PayPal buttons for that order id and reports approval. verify() performs
 * the capture server-side and checks the captured amount — approval alone
 * moves no money, and a substituted order id is rejected before any call.
 *
 * The admin's `isLive` flag picks the sandbox or live environment.
 */
class PaypalGateway
{
    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('paypal') ?? [];
    }

    private function base(): string
    {
        return ($this->row()['isLive'] ?? '') === 'true'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public function configured(): bool
    {
        $row = $this->row();

        return strlen((string) ($row['app_id'] ?? '')) > 20
            && strlen((string) ($row['secret_key'] ?? '')) > 20;
    }

    public function currency(): string
    {
        return strtoupper((string) $this->settings->get('currency_code', 'USD'));
    }

    /** OAuth client-credentials token, cached until shortly before expiry. */
    private function token(): ?string
    {
        $cached = Cache::get('cabme.paypal.token');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::withBasicAuth(
                (string) $this->row()['app_id'],
                (string) $this->row()['secret_key'],
            )->asForm()->timeout(20)->post($this->base().'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);
        } catch (ConnectionException $e) {
            Log::warning('PayPal unreachable while authenticating', ['error' => $e->getMessage()]);

            return null;
        }

        $token = (string) $response->json('access_token');

        if (! $response->successful() || $token === '') {
            Log::warning('PayPal rejected the credentials', ['body' => substr($response->body(), 0, 300)]);

            return null;
        }

        Cache::put('cabme.paypal.token', $token, max(60, (int) $response->json('expires_in') - 60));

        return $token;
    }

    /**
     * @param  array{name:string,email:string,phone:string}  $rider
     * @return array{client:array,pending:array}|null
     */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        if ($amount < 0.01) {
            return null;
        }

        $token = $this->token();

        if (! $token) {
            return null;
        }

        $value = number_format($amount, 2, '.', '');

        try {
            $response = Http::withToken($token)->timeout(20)
                ->post($this->base().'/v2/checkout/orders', [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'reference_id' => $reference,
                        'custom_id' => $reference,
                        'amount' => ['currency_code' => $this->currency(), 'value' => $value],
                    ]],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('PayPal unreachable while creating an order', ['error' => $e->getMessage()]);

            return null;
        }

        $orderId = (string) $response->json('id');

        if (! $response->successful() || $orderId === '') {
            Log::warning('PayPal rejected the order', ['body' => substr($response->body(), 0, 300)]);

            return null;
        }

        return [
            'client' => [
                'gateway' => 'paypal',
                'client_id' => (string) $this->row()['app_id'],
                'order_id' => $orderId,
                'amount' => $value,
                'currency' => $this->currency(),
            ],
            'pending' => [
                'order_id' => $orderId,
                'value' => $value,
            ],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string} */
    public function verify(Request $request, array $pending): array
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:64'],
        ]);

        // The order must be OURS — approval of some other order proves nothing.
        if ($data['order_id'] !== ($pending['order_id'] ?? '')) {
            return ['ok' => false, 'error' => __('That payment does not match this order.'), 'payment_id' => null];
        }

        $token = $this->token();

        if (! $token) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        try {
            $response = Http::withToken($token)->timeout(30)
                ->withBody('{}', 'application/json')
                ->post($this->base().'/v2/checkout/orders/'.rawurlencode($data['order_id']).'/capture');

            // A retried verify (double-click, dropped response) finds the
            // order already captured — read it back instead of failing.
            if ($response->status() === 422
                && str_contains($response->body(), 'ORDER_ALREADY_CAPTURED')) {
                $response = Http::withToken($token)->timeout(20)
                    ->get($this->base().'/v2/checkout/orders/'.rawurlencode($data['order_id']));
            }
        } catch (ConnectionException $e) {
            Log::warning('PayPal unreachable while capturing', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $order = $response->json();

        if (! $response->successful() || ! is_array($order)) {
            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        if (($order['status'] ?? '') !== 'COMPLETED') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;

        if (! is_array($capture)
            || ($capture['amount']['value'] ?? '') !== ($pending['value'] ?? '')
            || strtoupper((string) ($capture['amount']['currency_code'] ?? '')) !== $this->currency()) {
            Log::warning('PayPal amount/currency mismatch', [
                'expected' => $pending['value'] ?? null,
                'got' => $capture['amount'] ?? null,
            ]);

            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => (string) ($capture['id'] ?? $data['order_id'])];
    }
}
