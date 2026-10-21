<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use App\Support\GatewayCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Xendit hosted invoices (IDR). The rider pays on Xendit's page in a popup;
 * verify() re-reads the invoice server-side — status, our external id, and
 * the amount — before anything is credited.
 */
class XenditGateway
{
    private const API = 'https://api.xendit.co';

    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('xendit') ?? [];
    }

    public function configured(): bool
    {
        return str_starts_with((string) ($this->row()['key'] ?? ''), 'xnd_');
    }

    /** @return array{client:array,pending:array}|array{error:string}|null */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        $currency = GatewayCurrency::chargeCurrency('xendit', $this->settings->get('currency_code', 'USD'));
        $charge = GatewayCurrency::chargeAmount('xendit', $amount);

        $email = filter_var($rider['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'rider@cabme.invalid';
        $return = route('app.pay.return', ['gw' => 'xendit']);

        try {
            $response = Http::withBasicAuth((string) $this->row()['key'], '')
                ->timeout(25)
                ->post(self::API.'/v2/invoices', [
                    'external_id' => $reference,
                    'amount' => $charge,
                    'currency' => $currency,
                    'description' => config('app.name').' payment '.$reference,
                    'invoice_duration' => 900,
                    'payer_email' => $email,
                    'success_redirect_url' => $return,
                    'failure_redirect_url' => $return,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Xendit unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $url = (string) $response->json('invoice_url');
        $id = (string) $response->json('id');

        if (! $response->successful() || $url === '' || $id === '') {
            $message = (string) $response->json('message', '');
            Log::warning('Xendit rejected the invoice', ['body' => substr($response->body(), 0, 300)]);

            return $message !== '' ? ['error' => 'Xendit: '.$message] : null;
        }

        return [
            'client' => ['gateway' => 'xendit', 'flow' => 'redirect', 'url' => $url],
            'pending' => ['invoice_id' => $id, 'reference' => $reference, 'expected' => $charge, 'currency' => $currency],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string,retry?:bool} */
    public function verify(Request $request, array $pending): array
    {
        try {
            $response = Http::withBasicAuth((string) ($this->row()['key'] ?? ''), '')
                ->timeout(20)
                ->get(self::API.'/v2/invoices/'.rawurlencode((string) $pending['invoice_id']));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $invoice = $response->json();

        if (! $response->successful() || ! is_array($invoice)) {
            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        $status = (string) ($invoice['status'] ?? '');

        if ($status === 'PENDING') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null, 'retry' => true];
        }

        if (! in_array($status, ['PAID', 'SETTLED'], true)) {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        if ((float) ($invoice['amount'] ?? -1) + 0.01 < (float) $pending['expected']
            || ($invoice['external_id'] ?? '') !== ($pending['reference'] ?? '')) {
            Log::warning('Xendit amount/reference mismatch', ['pending' => $pending['expected'], 'got' => $invoice['amount'] ?? null]);

            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => (string) $pending['invoice_id']];
    }
}
