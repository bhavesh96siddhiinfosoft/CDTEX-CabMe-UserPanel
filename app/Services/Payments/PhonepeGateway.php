<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use App\Support\GatewayCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PhonePe checkout v2 (INR). The rider pays on PhonePe's hosted page in a
 * popup; verify() asks the order-status API — COMPLETED and the amount in
 * paise — before anything is credited.
 */
class PhonepeGateway
{
    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('phonepe') ?? [];
    }

    private function sandbox(): bool
    {
        return ($this->row()['isSandboxEnabled'] ?? 'true') === 'true';
    }

    private function base(): string
    {
        return $this->sandbox()
            ? 'https://api-preprod.phonepe.com/apis/pg-sandbox'
            : 'https://api.phonepe.com/apis/pg';
    }

    public function configured(): bool
    {
        $row = $this->row();

        return strlen((string) ($row['clientpublishableKey'] ?? '')) > 8
            && strlen((string) ($row['secret_key'] ?? '')) > 8;
    }

    private function token(): ?string
    {
        $url = $this->sandbox()
            ? 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token'
            : 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token';

        try {
            $response = Http::asForm()->timeout(20)->post($url, [
                'client_id' => (string) $this->row()['clientpublishableKey'],
                'client_secret' => (string) $this->row()['secret_key'],
                'grant_type' => 'client_credentials',
                'client_version' => '1',
            ]);
        } catch (\Throwable $e) {
            Log::warning('PhonePe unreachable while authenticating', ['error' => $e->getMessage()]);

            return null;
        }

        $token = (string) $response->json('access_token');

        return $token !== '' ? $token : null;
    }

    /** @return array{client:array,pending:array}|array{error:string}|null */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        $token = $this->token();

        if (! $token) {
            return ['error' => 'PhonePe: '.__('Could not reach the payment provider.')];
        }

        $minor = (int) round(GatewayCurrency::chargeAmount('phonepe', $amount) * 100);

        try {
            $response = Http::withHeaders(['Authorization' => "O-Bearer $token"])->timeout(25)
                ->post($this->base().'/checkout/v2/pay', [
                    'merchantOrderId' => $reference,
                    'amount' => $minor,
                    'paymentFlow' => [
                        'type' => 'PG_CHECKOUT',
                        'merchantUrls' => ['redirectUrl' => route('app.pay.return', ['gw' => 'phonepe'])],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('PhonePe unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $url = (string) $response->json('redirectUrl');

        if (! $response->successful() || $url === '') {
            $message = (string) $response->json('message', '');
            Log::warning('PhonePe rejected the order', ['body' => substr($response->body(), 0, 300)]);

            return $message !== '' ? ['error' => 'PhonePe: '.$message] : null;
        }

        return [
            'client' => ['gateway' => 'phonepe', 'flow' => 'redirect', 'url' => $url],
            'pending' => ['order_id' => $reference, 'minor' => $minor],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string,retry?:bool} */
    public function verify(Request $request, array $pending): array
    {
        $token = $this->token();

        if (! $token) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        try {
            $response = Http::withHeaders(['Authorization' => "O-Bearer $token"])->timeout(20)
                ->get($this->base().'/checkout/v2/order/'.rawurlencode((string) $pending['order_id']).'/status');
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $state = (string) $response->json('state', '');

        if ($state === 'PENDING') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null, 'retry' => true];
        }

        if ($state !== 'COMPLETED') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        if ((int) $response->json('amount', -1) !== (int) $pending['minor']) {
            Log::warning('PhonePe amount mismatch', ['pending' => $pending['minor'], 'got' => $response->json('amount')]);

            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => (string) ($response->json('orderId') ?: $pending['order_id'])];
    }
}
