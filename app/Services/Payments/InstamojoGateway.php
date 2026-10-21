<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Instamojo payment requests (INR). The rider pays on Instamojo's hosted
 * page in a popup; verify() re-reads the payment request server-side —
 * completed status and the amount — before anything is credited.
 *
 * Admin columns: clientpublishableKey = API client id, secret_key = client
 * secret (Settings → Payment → Instamojo).
 */
class InstamojoGateway
{
    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('instamojo') ?? [];
    }

    private function sandbox(): bool
    {
        return ($this->row()['isSandboxEnabled'] ?? 'true') === 'true';
    }

    public function configured(): bool
    {
        $row = $this->row();

        return strlen((string) ($row['clientpublishableKey'] ?? '')) > 5
            && strlen((string) ($row['secret_key'] ?? '')) > 5;
    }

    /** @return array{token:?string,base:string,error:string} */
    private function authenticate(): array
    {
        // test.instamojo.com no longer resolves (Instamojo retired its
        // sandbox host) — with the sandbox toggle on, fall through to the
        // live hosts rather than dead-ending, exactly as goride does.
        $bases = $this->sandbox()
            ? ['https://test.instamojo.com', 'https://api.instamojo.com', 'https://www.instamojo.com']
            : ['https://api.instamojo.com', 'https://www.instamojo.com'];

        $lastError = __('Could not reach the payment provider.');

        foreach ($bases as $base) {
            try {
                $response = Http::asForm()->timeout(20)->post($base.'/oauth2/token/', [
                    'grant_type' => 'client_credentials',
                    'client_id' => (string) $this->row()['clientpublishableKey'],
                    'client_secret' => (string) $this->row()['secret_key'],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Instamojo unreachable while authenticating', ['base' => $base, 'error' => $e->getMessage()]);

                continue;
            }

            $token = (string) $response->json('access_token');

            if ($token !== '') {
                return ['token' => $token, 'base' => $base, 'error' => ''];
            }

            $lastError = (string) ($response->json('error_description')
                ?: $response->json('error')
                ?: $response->json('message', ''));
            Log::warning('Instamojo rejected the credentials', ['base' => $base, 'body' => substr($response->body(), 0, 200)]);
        }

        return ['token' => null, 'base' => end($bases), 'error' => $lastError];
    }

    /** @return array{client:array,pending:array}|array{error:string}|null */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        $value = round($amount, 2);

        if ($value < 1) {
            return ['error' => 'Instamojo: '.__('The paid amount does not match this order.')];
        }

        $auth = $this->authenticate();

        if (! $auth['token']) {
            // "invalid_client" here means the keys are for the wrong mode —
            // Instamojo API credentials come from instamojo.com → Integrations.
            return ['error' => 'Instamojo: '.($auth['error'] ?: __('Could not reach the payment provider.'))];
        }

        $email = filter_var($rider['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'rider@cabme.invalid';
        $phone = preg_replace('/\D/', '', (string) ($rider['phone'] ?? '')) ?: '9999999999';

        try {
            // Newer accounts take JSON on payment-requests; older ones the
            // form-encoded underscore route — goride ships both, so keep both.
            $response = Http::withToken($auth['token'])->acceptJson()->timeout(30)
                ->post($auth['base'].'/v2/payment-requests/', [
                    'purpose' => config('app.name').' payment',
                    'amount' => $value,
                    'currency' => 'INR',
                    'buyer_name' => (string) ($rider['name'] ?? 'Rider'),
                    'email' => $email,
                    'phone' => $phone,
                    'redirect_url' => route('app.pay.return', ['gw' => 'instamojo']),
                    'send_email' => false,
                    'send_sms' => false,
                    'allow_repeated_payments' => false,
                ]);

            if (! $response->successful()) {
                $response = Http::withToken($auth['token'])->asForm()->acceptJson()->timeout(30)
                    ->post($auth['base'].'/v2/payment_requests/', [
                        'purpose' => config('app.name').' payment',
                        'amount' => number_format($value, 2, '.', ''),
                        'redirect_url' => route('app.pay.return', ['gw' => 'instamojo']),
                        'send_email' => 'false',
                        'send_sms' => 'false',
                        'allow_repeated_payments' => 'false',
                    ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Instamojo unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $url = (string) ($response->json('payment_request.longurl') ?: $response->json('longurl', ''));
        $id = (string) ($response->json('payment_request.id') ?: $response->json('id', ''));

        if ($url === '' || $id === '') {
            $message = (string) ($response->json('message') ?: $response->json('payment_request.message', ''));
            Log::warning('Instamojo rejected the request', ['body' => substr($response->body(), 0, 300)]);

            return $message !== '' ? ['error' => 'Instamojo: '.$message] : null;
        }

        return [
            'client' => ['gateway' => 'instamojo', 'flow' => 'redirect', 'url' => $url],
            'pending' => ['request_id' => $id, 'expected' => $value],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string,retry?:bool} */
    public function verify(Request $request, array $pending): array
    {
        $auth = $this->authenticate();

        if (! $auth['token']) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $id = rawurlencode((string) $pending['request_id']);

        try {
            $response = Http::withToken($auth['token'])->acceptJson()->timeout(30)
                ->get($auth['base']."/v2/payment-requests/{$id}/");

            if (! $response->successful()) {
                $response = Http::withToken($auth['token'])->acceptJson()->timeout(30)
                    ->get($auth['base']."/v2/payment_requests/{$id}/");
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $body = $response->json('payment_request') ?? $response->json();

        if (! is_array($body)) {
            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        $status = strtolower((string) ($body['status'] ?? ''));

        if (in_array($status, ['pending', 'sent'], true)) {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null, 'retry' => true];
        }

        if (! in_array($status, ['completed', 'paid'], true)) {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        if ((float) ($body['amount'] ?? -1) + 0.01 < (float) $pending['expected']) {
            Log::warning('Instamojo amount mismatch', ['pending' => $pending['expected'], 'got' => $body['amount'] ?? null]);

            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        $paymentId = (string) ($body['payments'][0]['id'] ?? $body['payments'][0] ?? $pending['request_id']);

        return ['ok' => true, 'error' => null, 'payment_id' => $paymentId];
    }
}
