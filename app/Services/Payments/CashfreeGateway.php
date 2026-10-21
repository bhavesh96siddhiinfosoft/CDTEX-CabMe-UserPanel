<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use App\Support\GatewayCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cashfree payment links (INR). The rider pays on Cashfree's hosted page in
 * a popup; verify() re-reads the link server-side — PAID and the amount —
 * before anything is credited.
 */
class CashfreeGateway
{
    private const API_VERSION = '2023-08-01';

    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('cashfree') ?? [];
    }

    private function sandbox(): bool
    {
        return ($this->row()['isSandboxEnabled'] ?? 'true') === 'true';
    }

    private function base(): string
    {
        return $this->sandbox() ? 'https://sandbox.cashfree.com' : 'https://api.cashfree.com';
    }

    private function headers(): array
    {
        return [
            'x-client-id' => (string) ($this->row()['clientpublishableKey'] ?? ''),
            'x-client-secret' => (string) ($this->row()['secret_key'] ?? ''),
            'x-api-version' => self::API_VERSION,
        ];
    }

    public function configured(): bool
    {
        $row = $this->row();

        return strlen((string) ($row['clientpublishableKey'] ?? '')) > 8
            && strlen((string) ($row['secret_key'] ?? '')) > 8;
    }

    /** @return array{client:array,pending:array}|array{error:string}|null */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        $value = GatewayCurrency::chargeAmount('cashfree', $amount);

        // Cashfree links use a link_id charset narrower than our references.
        $linkId = str_replace(['_'], '-', $reference);

        $phone = preg_replace('/\D/', '', (string) ($rider['phone'] ?? ''));
        $phone = strlen($phone) >= 10 ? substr($phone, -10) : '9999999999';
        $email = filter_var($rider['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'rider@cabme.invalid';

        try {
            $response = Http::withHeaders($this->headers())->timeout(25)
                ->post($this->base().'/pg/links', [
                    'link_id' => $linkId,
                    'link_amount' => $value,
                    'link_currency' => GatewayCurrency::chargeCurrency('cashfree', ''),
                    'link_purpose' => config('app.name').' payment',
                    'customer_details' => [
                        'customer_name' => (string) ($rider['name'] ?? 'Rider'),
                        'customer_email' => $email,
                        'customer_phone' => $phone,
                    ],
                    'link_meta' => ['return_url' => route('app.pay.return', ['gw' => 'cashfree'])],
                    'link_notify' => ['send_sms' => false, 'send_email' => false],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Cashfree unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $url = (string) $response->json('link_url');

        if (! $response->successful() || $url === '') {
            $message = (string) $response->json('message', '');
            Log::warning('Cashfree rejected the link', ['body' => substr($response->body(), 0, 300)]);

            return $message !== '' ? ['error' => 'Cashfree: '.$message] : null;
        }

        return [
            'client' => ['gateway' => 'cashfree', 'flow' => 'redirect', 'url' => $url],
            'pending' => ['link_id' => $linkId, 'expected' => $value],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string,retry?:bool} */
    public function verify(Request $request, array $pending): array
    {
        try {
            $response = Http::withHeaders($this->headers())->timeout(20)
                ->get($this->base().'/pg/links/'.rawurlencode((string) $pending['link_id']));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $link = $response->json();

        if (! $response->successful() || ! is_array($link)) {
            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        $status = (string) ($link['link_status'] ?? '');

        if (in_array($status, ['ACTIVE', 'PARTIALLY_PAID'], true)) {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null, 'retry' => true];
        }

        if ($status !== 'PAID') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        if ((float) ($link['link_amount_paid'] ?? $link['link_amount'] ?? -1) + 0.01 < (float) $pending['expected']) {
            Log::warning('Cashfree amount mismatch', ['pending' => $pending['expected'], 'got' => $link['link_amount_paid'] ?? null]);

            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => (string) ($link['cf_link_id'] ?? $pending['link_id'])];
    }
}
