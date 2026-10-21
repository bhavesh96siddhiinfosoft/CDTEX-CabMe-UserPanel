<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use App\Support\GatewayCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayMongo payment links (PHP — Philippine peso). The rider pays on
 * PayMongo's hosted page in a popup; verify() re-reads the link server-side
 * — paid status and the amount in centavos — before anything is credited.
 *
 * Admin column: secret_key (Settings → Payment → PayMongo).
 */
class PaymongoGateway
{
    private const API = 'https://api.paymongo.com/v1';

    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('paymongo') ?? [];
    }

    public function configured(): bool
    {
        return strlen((string) ($this->row()['secret_key'] ?? '')) > 8;
    }

    /** @return array{client:array,pending:array}|array{error:string}|null */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        $minor = (int) round(GatewayCurrency::chargeAmount('paymongo', $amount) * 100);
        $return = route('app.pay.return', ['gw' => 'paymongo']);

        try {
            $response = Http::withBasicAuth((string) $this->row()['secret_key'], '')
                ->timeout(25)
                ->post(self::API.'/links', [
                    'data' => ['attributes' => [
                        'amount' => $minor,
                        'description' => config('app.name').' payment '.$reference,
                        'currency' => GatewayCurrency::chargeCurrency('paymongo', ''),
                        'redirect' => ['success' => $return, 'failed' => $return],
                    ]],
                ]);
        } catch (\Throwable $e) {
            Log::warning('PayMongo unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $url = (string) $response->json('data.attributes.checkout_url');
        $id = (string) $response->json('data.id');

        if (! $response->successful() || $url === '' || $id === '') {
            $message = (string) $response->json('errors.0.detail', '');
            Log::warning('PayMongo rejected the link', ['body' => substr($response->body(), 0, 300)]);

            return $message !== '' ? ['error' => 'PayMongo: '.$message] : null;
        }

        return [
            'client' => ['gateway' => 'paymongo', 'flow' => 'redirect', 'url' => $url],
            'pending' => ['link_id' => $id, 'minor' => $minor],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string,retry?:bool} */
    public function verify(Request $request, array $pending): array
    {
        try {
            $response = Http::withBasicAuth((string) ($this->row()['secret_key'] ?? ''), '')
                ->timeout(20)
                ->get(self::API.'/links/'.rawurlencode((string) $pending['link_id']));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $attributes = $response->json('data.attributes');

        if (! $response->successful() || ! is_array($attributes)) {
            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        $status = (string) ($attributes['status'] ?? '');

        if ($status === 'unpaid') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null, 'retry' => true];
        }

        if ($status !== 'paid') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        if ((int) ($attributes['amount'] ?? -1) !== (int) $pending['minor']) {
            Log::warning('PayMongo amount mismatch', ['pending' => $pending['minor'], 'got' => $attributes['amount'] ?? null]);

            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        $paymentId = (string) ($attributes['payments'][0]['data']['id'] ?? $pending['link_id']);

        return ['ok' => true, 'error' => null, 'payment_id' => $paymentId];
    }
}
