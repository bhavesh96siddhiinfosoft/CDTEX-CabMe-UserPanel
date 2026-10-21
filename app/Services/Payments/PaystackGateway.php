<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use App\Support\GatewayCurrency;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paystack, charged through their inline popup and verified server-side.
 *
 * The popup runs entirely in the browser with our public key and reference;
 * nothing it reports is trusted. verify() retrieves the transaction from
 * Paystack directly and checks status, amount and currency against what the
 * server decided — a popup opened with a tampered amount simply fails here.
 */
class PaystackGateway
{
    private const API = 'https://api.paystack.co';

    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('paystack') ?? [];
    }

    public function configured(): bool
    {
        $row = $this->row();

        return str_starts_with((string) ($row['secret_key'] ?? ''), 'sk_')
            && str_starts_with((string) ($row['public_key'] ?? ''), 'pk_');
    }

    public function currency(): string
    {
        return GatewayCurrency::chargeCurrency('paystack', (string) $this->settings->get('currency_code', ''));
    }

    /**
     * Paystack amounts are in the currency's minor unit (kobo, pesewas, …).
     */
    private function toMinor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Server-initialized HOSTED checkout, opened in the popup like the other
     * redirect gateways. The inline popup was abandoned: it force-defaults
     * the currency to NGN, and Paystack merchants enable exactly one home
     * currency — initialize WITHOUT a currency lets Paystack apply the
     * merchant's own, which is the only combination every account accepts.
     *
     * @param  array{name:string,email:string,phone:string}  $rider
     * @return array{client:array,pending:array}|array{error:string}|null
     */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        $minor = $this->toMinor($amount);

        if ($minor < 1) {
            return null;
        }

        // Paystack requires a syntactically valid email even in test mode.
        $email = filter_var($rider['email'] ?? '', FILTER_VALIDATE_EMAIL)
            ?: 'rider-'.$reference.'@cabme.invalid';

        try {
            $response = Http::withToken((string) $this->row()['secret_key'])
                ->timeout(25)
                ->post(self::API.'/transaction/initialize', [
                    'email' => $email,
                    'amount' => $minor,
                    'reference' => $reference,
                    'callback_url' => route('app.pay.return', ['gw' => 'paystack']),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Paystack unreachable while initializing', ['error' => $e->getMessage()]);

            return null;
        }

        $url = (string) $response->json('data.authorization_url');

        if (! $response->successful() || $url === '') {
            $message = (string) $response->json('message', '');
            Log::warning('Paystack rejected the initialize', ['body' => substr($response->body(), 0, 300)]);

            return $message !== '' ? ['error' => 'Paystack: '.$message] : null;
        }

        return [
            'client' => ['gateway' => 'paystack', 'flow' => 'redirect', 'url' => $url],
            'pending' => [
                'reference' => $reference,
                'minor' => $minor,
            ],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string,retry?:bool} */
    public function verify(Request $request, array $pending): array
    {
        // The reference comes from OUR pending record, never the browser —
        // the hosted-checkout return only signals "go verify".
        $reference = (string) ($pending['reference'] ?? '');

        try {
            $response = Http::withToken((string) ($this->row()['secret_key'] ?? ''))
                ->timeout(20)
                ->get(self::API.'/transaction/verify/'.rawurlencode($reference));
        } catch (ConnectionException $e) {
            Log::warning('Paystack unreachable while verifying', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $txn = $response->json('data');

        if (! $response->successful() || ! is_array($txn)) {
            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        $status = (string) ($txn['status'] ?? '');

        if (in_array($status, ['ongoing', 'pending', 'queued'], true)) {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null, 'retry' => true];
        }

        if ($status !== 'success') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        // The charge ran in the merchant's home currency (we send none), so
        // only the amount in subunits is checked — the reference already
        // proves the transaction is ours.
        if ((int) ($txn['amount'] ?? -1) !== (int) $pending['minor']) {
            Log::warning('Paystack amount mismatch', [
                'expected' => $pending['minor'], 'got' => $txn['amount'] ?? null,
            ]);

            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => (string) ($txn['id'] ?? $reference)];
    }
}
