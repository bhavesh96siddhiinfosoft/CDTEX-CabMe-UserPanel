<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use App\Support\GatewayCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mercado Pago Checkout Pro. The rider pays on the hosted page in a popup;
 * the return carries a payment id which verify() re-reads server-side —
 * status approved, OUR external reference, and the amount — before anything
 * is credited.
 */
class MercadopagoGateway
{
    private const API = 'https://api.mercadopago.com';

    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('mercado') ?? [];
    }

    private function sandbox(): bool
    {
        return ($this->row()['isSandboxEnabled'] ?? 'true') === 'true';
    }

    public function configured(): bool
    {
        return strlen((string) ($this->row()['accesstoken'] ?? '')) > 20;
    }

    private function currency(): string
    {
        return GatewayCurrency::chargeCurrency('mercadopago', $this->settings->get('currency_code', 'USD'));
    }

    /** @return array{client:array,pending:array}|array{error:string}|null */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        $currency = $this->currency();
        $return = route('app.pay.return', ['gw' => 'mercadopago']);

        try {
            $response = Http::withToken((string) $this->row()['accesstoken'])
                ->timeout(25)
                ->post(self::API.'/checkout/preferences', [
                    'items' => [[
                        'title' => config('app.name').' payment',
                        'quantity' => 1,
                        'currency_id' => $currency,
                        'unit_price' => round($amount, 2),
                    ]],
                    'external_reference' => $reference,
                    'back_urls' => ['success' => $return, 'failure' => $return, 'pending' => $return],
                    'auto_return' => 'approved',
                ]);
        } catch (\Throwable $e) {
            Log::warning('Mercado Pago unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $url = $this->sandbox()
            ? (string) ($response->json('sandbox_init_point') ?: $response->json('init_point'))
            : (string) ($response->json('init_point') ?: $response->json('sandbox_init_point'));

        if (! $response->successful() || $url === '') {
            $message = (string) $response->json('message', '');
            Log::warning('Mercado Pago rejected the preference', ['body' => substr($response->body(), 0, 300)]);

            return $message !== '' ? ['error' => 'Mercado Pago: '.$message] : null;
        }

        return [
            'client' => ['gateway' => 'mercadopago', 'flow' => 'redirect', 'url' => $url],
            'pending' => ['reference' => $reference, 'expected' => round($amount, 2), 'currency' => $currency],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string,retry?:bool} */
    public function verify(Request $request, array $pending): array
    {
        // Checkout Pro appends the payment id to the return URL; the runner
        // forwards the query. Nothing else from the browser is trusted.
        $paymentId = (string) ($request->input('payment_id') ?: $request->input('collection_id', ''));

        if (! preg_match('/^\d{5,20}$/', $paymentId)) {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        try {
            $response = Http::withToken((string) ($this->row()['accesstoken'] ?? ''))
                ->timeout(20)
                ->get(self::API.'/v1/payments/'.$paymentId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $payment = $response->json();

        if (! $response->successful() || ! is_array($payment)) {
            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        if (($payment['status'] ?? '') !== 'approved') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        if (($payment['external_reference'] ?? '') !== ($pending['reference'] ?? '')
            || (float) ($payment['transaction_amount'] ?? -1) + 0.01 < (float) $pending['expected']) {
            Log::warning('Mercado Pago mismatch', ['pending' => $pending, 'got' => [
                'reference' => $payment['external_reference'] ?? null,
                'amount' => $payment['transaction_amount'] ?? null,
            ]]);

            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => $paymentId];
    }
}
