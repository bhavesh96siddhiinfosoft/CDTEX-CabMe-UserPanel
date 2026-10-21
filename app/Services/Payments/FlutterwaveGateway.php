<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use App\Support\GatewayCurrency;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Flutterwave, charged through their inline checkout and verified server-side.
 *
 * The checkout modal runs in the browser with our public key and tx_ref; the
 * browser only reports a transaction id afterwards. verify() retrieves that
 * transaction from Flutterwave directly and checks status, tx_ref, amount and
 * currency against what the server decided.
 */
class FlutterwaveGateway
{
    private const API = 'https://api.flutterwave.com/v3';

    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('flutter') ?? [];
    }

    public function configured(): bool
    {
        $row = $this->row();

        return str_starts_with((string) ($row['secret_key'] ?? ''), 'FLWSECK')
            && str_starts_with((string) ($row['public_key'] ?? ''), 'FLWPUBK');
    }

    public function currency(): string
    {
        return GatewayCurrency::chargeCurrency('flutterwave', (string) $this->settings->get('currency_code', ''));
    }

    /**
     * Flutterwave takes amounts in MAJOR units (20.50, not 2050).
     *
     * @param  array{name:string,email:string,phone:string}  $rider
     * @return array{client:array,pending:array}|null
     */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        if ($amount < 0.01) {
            return null;
        }

        $email = filter_var($rider['email'] ?? '', FILTER_VALIDATE_EMAIL)
            ?: 'rider-'.$reference.'@cabme.invalid';

        return [
            'client' => [
                'gateway' => 'flutterwave',
                'public_key' => (string) $this->row()['public_key'],
                'tx_ref' => $reference,
                'amount' => round($amount, 2),
                'currency' => $this->currency(),
                'customer' => [
                    'email' => $email,
                    'name' => (string) ($rider['name'] ?? ''),
                    'phone_number' => (string) ($rider['phone'] ?? ''),
                ],
            ],
            'pending' => [
                'tx_ref' => $reference,
                'expected' => round($amount, 2),
            ],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string} */
    public function verify(Request $request, array $pending): array
    {
        $data = $request->validate([
            'transaction_id' => ['required', 'string', 'max:64', 'regex:/^[0-9]+$/'],
        ]);

        try {
            $response = Http::withToken((string) ($this->row()['secret_key'] ?? ''))
                ->timeout(20)
                ->get(self::API.'/transactions/'.$data['transaction_id'].'/verify');
        } catch (ConnectionException $e) {
            Log::warning('Flutterwave unreachable while verifying', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $txn = $response->json('data');

        if (! $response->successful() || ! is_array($txn)) {
            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        // The transaction must be OURS (tx_ref), successful, and for the
        // amount and currency the server decided.
        if (($txn['tx_ref'] ?? '') !== ($pending['tx_ref'] ?? '')) {
            return ['ok' => false, 'error' => __('That payment does not match this order.'), 'payment_id' => null];
        }

        if (($txn['status'] ?? '') !== 'successful') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        if ((float) ($txn['amount'] ?? -1) + 0.01 < (float) $pending['expected']
            || strtoupper((string) ($txn['currency'] ?? '')) !== $this->currency()) {
            Log::warning('Flutterwave amount/currency mismatch', [
                'expected' => $pending['expected'], 'got' => $txn['amount'] ?? null,
            ]);

            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => (string) ($txn['id'] ?? $data['transaction_id'])];
    }
}
