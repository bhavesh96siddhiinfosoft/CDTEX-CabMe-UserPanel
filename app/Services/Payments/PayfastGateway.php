<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use App\Support\GatewayCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * PayFast (ZAR) — a signed form POST to their hosted page in a popup.
 *
 * PayFast has no order-query API: confirmation arrives on the ITN webhook
 * (see PayfastWebhookController), which validates the notification with
 * PayFast server-to-server and records it in the cache. verify() trusts only
 * that record — the browser's return proves nothing — and reports "retry"
 * while the ITN is still in flight. NOTE: the ITN needs a publicly reachable
 * notify URL, so this cannot complete on a localhost-only panel.
 */
class PayfastGateway
{
    public const PAID_CACHE = 'cabme.payfast.paid.';

    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    public function row(): array
    {
        return $this->creds->row('payfast') ?? [];
    }

    public function sandbox(): bool
    {
        return ($this->row()['isSandboxEnabled'] ?? 'true') === 'true';
    }

    public function configured(): bool
    {
        $row = $this->row();

        return strlen((string) ($row['merchant_Id'] ?? '')) > 5
            && strlen((string) ($row['merchant_key'] ?? '')) > 5;
    }

    /** @return array{client:array,pending:array}|array{error:string}|null */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        $value = number_format(GatewayCurrency::chargeAmount('payfast', $amount), 2, '.', '');
        $return = route('app.pay.return', ['gw' => 'payfast']);

        $fields = [
            'merchant_id' => (string) $this->row()['merchant_Id'],
            'merchant_key' => (string) $this->row()['merchant_key'],
            'return_url' => $return,
            'cancel_url' => $return,
            'notify_url' => route('webhooks.payfast'),
            'amount' => $value,
            'item_name' => config('app.name').' payment',
            'm_payment_id' => $reference,
        ];

        if (! empty($rider['name'])) {
            $fields['name_first'] = (string) $rider['name'];
        }

        return [
            'client' => [
                'gateway' => 'payfast',
                'flow' => 'form',
                'url' => $this->sandbox() ? 'https://sandbox.payfast.co.za/eng/process' : 'https://www.payfast.co.za/eng/process',
                'fields' => $fields,
            ],
            'pending' => ['reference' => $reference, 'value' => $value],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string,retry?:bool} */
    public function verify(Request $request, array $pending): array
    {
        $paid = Cache::get(self::PAID_CACHE.$pending['reference']);

        if (! is_array($paid)) {
            // The ITN usually lands within seconds of the return redirect.
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null, 'retry' => true];
        }

        if (number_format((float) ($paid['amount_gross'] ?? -1), 2, '.', '') !== (string) $pending['value']) {
            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        Cache::forget(self::PAID_CACHE.$pending['reference']);

        return ['ok' => true, 'error' => null, 'payment_id' => (string) ($paid['pf_payment_id'] ?? $pending['reference'])];
    }
}
