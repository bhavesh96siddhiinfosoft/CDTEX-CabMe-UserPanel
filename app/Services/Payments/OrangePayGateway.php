<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use App\Support\GatewayCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Orange Money web payments. The rider pays on Orange's hosted page in a
 * popup; verify() asks the transaction-status API with the pay token from
 * the pending record before anything is credited. The sandbox environment
 * charges in Orange's test currency (OUV), per their API rules.
 */
class OrangePayGateway
{
    private const API = 'https://api.orange.com';

    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('orange') ?? [];
    }

    private function sandbox(): bool
    {
        return ($this->row()['isSandboxEnabled'] ?? 'true') === 'true';
    }

    public function configured(): bool
    {
        $row = $this->row();

        return strlen((string) ($row['key'] ?? '')) > 20
            && strlen((string) ($row['merchant_key'] ?? '')) > 4;
    }

    private function token(): ?string
    {
        try {
            $response = Http::asForm()
                ->withHeaders(['Authorization' => 'Basic '.(string) $this->row()['key']])
                ->timeout(20)
                ->post(self::API.'/oauth/v3/token', ['grant_type' => 'client_credentials']);
        } catch (\Throwable $e) {
            Log::warning('Orange unreachable while authenticating', ['error' => $e->getMessage()]);

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
            return ['error' => 'OrangePay: '.__('Could not reach the payment provider.')];
        }

        $path = $this->sandbox() ? 'dev' : 'cm';
        $currency = $this->sandbox()
            ? 'OUV'
            : GatewayCurrency::chargeCurrency('orangepay', $this->settings->get('currency_code', 'USD'));
        $value = (int) round($amount);
        $return = route('app.pay.return', ['gw' => 'orangepay']);

        try {
            $response = Http::withToken($token)->timeout(25)
                ->post(self::API."/orange-money-webpay/$path/v1/webpayment", [
                    'merchant_key' => (string) $this->row()['merchant_key'],
                    'currency' => $currency,
                    'order_id' => $reference,
                    'amount' => $value,
                    'return_url' => $return,
                    'cancel_url' => $return,
                    'notif_url' => $return,
                    'lang' => 'en',
                    'reference' => config('app.name'),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Orange unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $url = (string) $response->json('payment_url');
        $payToken = (string) $response->json('pay_token');

        if (! $response->successful() || $url === '' || $payToken === '') {
            Log::warning('Orange rejected the payment', ['body' => substr($response->body(), 0, 300)]);

            $message = (string) ($response->json('description') ?: $response->json('message', ''));

            return $message !== '' ? ['error' => 'OrangePay: '.$message] : null;
        }

        return [
            'client' => ['gateway' => 'orangepay', 'flow' => 'redirect', 'url' => $url],
            'pending' => ['order_id' => $reference, 'pay_token' => $payToken, 'value' => $value],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string,retry?:bool} */
    public function verify(Request $request, array $pending): array
    {
        $token = $this->token();

        if (! $token) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $path = $this->sandbox() ? 'dev' : 'cm';

        try {
            $response = Http::withToken($token)->timeout(20)
                ->post(self::API."/orange-money-webpay/$path/v1/transactionstatus", [
                    'order_id' => (string) $pending['order_id'],
                    'amount' => (int) $pending['value'],
                    'pay_token' => (string) $pending['pay_token'],
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $status = (string) $response->json('status', '');

        if (in_array($status, ['PENDING', 'INITIATED'], true)) {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null, 'retry' => true];
        }

        if ($status !== 'SUCCESS') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => (string) ($response->json('txnid') ?: $pending['order_id'])];
    }
}
