<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use App\Support\GatewayCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Midtrans Snap (IDR). The rider pays on the hosted Snap page in a popup;
 * verify() asks the status API — settlement/capture and the gross amount —
 * before anything is credited. The admin's sandbox flag picks the host.
 */
class MidtransGateway
{
    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('midtrans') ?? [];
    }

    private function sandbox(): bool
    {
        return ($this->row()['isSandboxEnabled'] ?? 'true') === 'true';
    }

    public function configured(): bool
    {
        return str_contains((string) ($this->row()['key'] ?? ''), 'Mid-server');
    }

    /** @return array{client:array,pending:array}|array{error:string}|null */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        $charge = GatewayCurrency::chargeAmount('midtrans', $amount);
        $base = $this->sandbox() ? 'https://app.sandbox.midtrans.com' : 'https://app.midtrans.com';

        $email = filter_var($rider['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'rider@cabme.invalid';

        try {
            $response = Http::withBasicAuth((string) $this->row()['key'], '')
                ->acceptJson()
                ->timeout(25)
                ->post($base.'/snap/v1/transactions', [
                    'transaction_details' => ['order_id' => $reference, 'gross_amount' => $charge],
                    'customer_details' => ['email' => $email],
                    'callbacks' => ['finish' => route('app.pay.return', ['gw' => 'midtrans'])],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Midtrans unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $url = (string) $response->json('redirect_url');

        if (! $response->successful() || $url === '') {
            Log::warning('Midtrans rejected the transaction', ['body' => substr($response->body(), 0, 300)]);

            return null;
        }

        return [
            'client' => ['gateway' => 'midtrans', 'flow' => 'redirect', 'url' => $url],
            'pending' => ['order_id' => $reference, 'expected' => $charge],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string,retry?:bool} */
    public function verify(Request $request, array $pending): array
    {
        $base = $this->sandbox() ? 'https://api.sandbox.midtrans.com' : 'https://api.midtrans.com';

        try {
            $response = Http::withBasicAuth((string) ($this->row()['key'] ?? ''), '')
                ->acceptJson()
                ->timeout(20)
                ->get($base.'/v2/'.rawurlencode((string) $pending['order_id']).'/status');
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $body = $response->json();

        if (! is_array($body)) {
            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        $status = (string) ($body['transaction_status'] ?? '');

        if (in_array($status, ['pending'], true)) {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null, 'retry' => true];
        }

        if (! in_array($status, ['settlement', 'capture'], true)) {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        if ((float) ($body['gross_amount'] ?? -1) + 0.01 < (float) $pending['expected']) {
            Log::warning('Midtrans amount mismatch', ['pending' => $pending['expected'], 'got' => $body['gross_amount'] ?? null]);

            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => (string) ($body['transaction_id'] ?? $pending['order_id'])];
    }
}
