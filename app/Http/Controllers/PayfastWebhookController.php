<?php

namespace App\Http\Controllers;

use App\Services\Payments\PayfastGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayFast ITN (Instant Transaction Notification).
 *
 * PayFast has no order-query API, so this webhook is the ONLY trustworthy
 * confirmation. Nothing in the notification is believed until PayFast
 * itself has validated the exact payload back to us; only then is the
 * payment recorded for PayfastGateway::verify() to consume.
 */
class PayfastWebhookController extends Controller
{
    public function __construct(private readonly PayfastGateway $gateway)
    {
    }

    public function handle(Request $request)
    {
        $data = $request->post();

        $reference = (string) ($data['m_payment_id'] ?? '');
        $status = (string) ($data['payment_status'] ?? '');

        if ($reference === '' || $status !== 'COMPLETE') {
            return response('OK');    // cancelled / pending — nothing to record
        }

        // Server-to-server confirmation with PayFast, exactly as their ITN
        // docs require: replay the payload to /eng/query/validate.
        $host = $this->gateway->sandbox() ? 'https://sandbox.payfast.co.za' : 'https://www.payfast.co.za';

        try {
            $confirmation = Http::asForm()->timeout(20)->post($host.'/eng/query/validate', $data);
        } catch (\Throwable $e) {
            Log::warning('PayFast ITN validation unreachable', ['error' => $e->getMessage()]);

            return response('OK');
        }

        if (trim($confirmation->body()) !== 'VALID') {
            Log::warning('PayFast ITN failed validation', ['reference' => $reference]);

            return response('OK');
        }

        // Belt and braces: the notified merchant must be ours.
        if ((string) ($data['merchant_id'] ?? '') !== (string) ($this->gateway->row()['merchant_Id'] ?? '')) {
            Log::warning('PayFast ITN for a different merchant', ['reference' => $reference]);

            return response('OK');
        }

        Cache::put(PayfastGateway::PAID_CACHE.$reference, [
            'pf_payment_id' => (string) ($data['pf_payment_id'] ?? ''),
            'amount_gross' => (string) ($data['amount_gross'] ?? ''),
        ], now()->addHours(2));

        return response('OK');
    }
}
