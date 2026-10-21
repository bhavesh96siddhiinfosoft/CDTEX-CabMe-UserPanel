<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use App\Support\GatewayCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Foloosi (AED). The server initialises the charge for ITS amount and hands
 * the browser only a reference token; Foloosi's own widget collects the
 * card in-page. Unlike the goride reference implementation — which trusted
 * the widget's postMessage — verify() here re-reads the transaction from
 * Foloosi's API (status, amount, and our reference token when reported)
 * before anything is credited.
 *
 * Admin column: merchant_key (Settings → Payment → Foloosi).
 */
class FoloosiGateway
{
    private const API = 'https://foloosi.com/aggregatorapi/web';

    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('foloosi') ?? [];
    }

    private function merchantKey(): string
    {
        return (string) ($this->row()['merchant_key'] ?? '');
    }

    public function configured(): bool
    {
        return strlen($this->merchantKey()) > 8;
    }

    /** @return array{client:array,pending:array}|array{error:string}|null */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        $value = round(GatewayCurrency::chargeAmount('foloosi', $amount), 2);

        try {
            $response = Http::withHeaders(['secret_key' => $this->merchantKey()])
                ->asForm()
                ->timeout(25)
                ->post(self::API.'/initialize-setup', [
                    'transaction_amount' => number_format($value, 2, '.', ''),
                    'currency' => GatewayCurrency::chargeCurrency('foloosi', ''),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Foloosi unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $token = (string) $response->json('data.reference_token');

        if (! $response->successful() || $token === '') {
            $message = (string) $response->json('message', '');
            Log::warning('Foloosi rejected the setup', ['body' => substr($response->body(), 0, 300)]);

            return $message !== '' ? ['error' => 'Foloosi: '.$message] : null;
        }

        return [
            'client' => [
                'gateway' => 'foloosi',
                'reference_token' => $token,
                // The widget takes the merchant key in the browser — that is
                // Foloosi's own integration model (same as goride).
                'merchant_key' => $this->merchantKey(),
            ],
            'pending' => ['reference_token' => $token, 'expected' => $value],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string,retry?:bool} */
    public function verify(Request $request, array $pending): array
    {
        $data = $request->validate([
            'transaction_no' => ['required', 'string', 'max:64'],
        ]);

        try {
            $response = Http::withHeaders(['secret_key' => $this->merchantKey()])
                ->timeout(20)
                ->get(self::API.'/transaction-detail/'.rawurlencode($data['transaction_no']));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $txn = $response->json('data');

        if (! $response->successful() || ! is_array($txn)) {
            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        $status = strtolower((string) ($txn['status'] ?? $txn['transaction_status'] ?? ''));

        if (in_array($status, ['pending', 'processing'], true)) {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null, 'retry' => true];
        }

        if (! in_array($status, ['success', 'paid', 'captured', 'completed'], true)) {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        $paid = (float) ($txn['transaction_amount'] ?? $txn['amount'] ?? -1);

        if ($paid + 0.01 < (float) $pending['expected']) {
            Log::warning('Foloosi amount mismatch', ['pending' => $pending['expected'], 'got' => $paid]);

            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        // When Foloosi echoes the reference token, it must be OURS.
        $tokenBack = (string) ($txn['reference_token'] ?? '');

        if ($tokenBack !== '' && $tokenBack !== (string) $pending['reference_token']) {
            return ['ok' => false, 'error' => __('That payment does not match this order.'), 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => $data['transaction_no']];
    }
}
