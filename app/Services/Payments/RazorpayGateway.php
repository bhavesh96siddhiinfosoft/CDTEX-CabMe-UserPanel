<?php

namespace App\Services\Payments;

use App\Services\Cabme\CatalogApi;
use App\Support\CabmeSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Razorpay, charged server-side.
 *
 * Credentials come from the ADMIN's payment settings (v1/payment-settings/),
 * the same single source the Flutter apps use — the operator configures every
 * gateway in the admin panel and nowhere else. The panel reads them
 * server-to-server and they never reach a browser: order creation and
 * verification both happen here, and only the public key id is sent to the
 * checkout widget.
 *
 * The amount is always the server's figure, and a callback is only trusted
 * after three checks: HMAC signature, captured status at Razorpay, and that
 * the captured amount and order match what we recorded.
 */
class RazorpayGateway
{
    private const API = 'https://api.razorpay.com/v1';

    /** Admin credentials cache: avoids one payment-settings call per step. */
    private const CREDS_CACHE = 'cabme.razorpay.creds';

    /** @var array{key:string,secret:string}|null */
    private ?array $credentials = null;

    public function __construct(
        private readonly CatalogApi $catalog,
        private readonly CabmeSettings $settings,
    ) {
    }

    public function configured(): bool
    {
        $creds = $this->credentials();

        return $creds['key'] !== '' && $creds['secret'] !== '';
    }

    public function keyId(): string
    {
        return $this->credentials()['key'];
    }

    private function secret(): string
    {
        return $this->credentials()['secret'];
    }

    /** @return array{key:string,secret:string} */
    private function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        // The admin's payment settings, exactly as the apps use them. Cached
        // briefly so order + verify do not each cost an extra API round trip.
        return $this->credentials = Cache::remember(self::CREDS_CACHE, 300, function () {
            $body = $this->catalog->paymentMethods()->raw;

            foreach ($body as $bucket) {
                if (! is_array($bucket)) {
                    continue;
                }

                foreach (array_is_list($bucket) ? $bucket : [$bucket] as $row) {
                    if (! is_array($row) || ! str_contains(strtolower((string) ($row['libelle'] ?? '')), 'razorpay')) {
                        continue;
                    }

                    if (($row['isEnabled'] ?? '') !== 'true') {
                        continue;
                    }

                    return [
                        'key' => trim((string) ($row['key'] ?? '')),
                        'secret' => trim((string) ($row['secret_key'] ?? '')),
                    ];
                }
            }

            return ['key' => '', 'secret' => ''];
        });
    }

    /**
     * ISO currency for charges — the admin's active currency (currency_code
     * from v1/settings/, i.e. the currency the operator activated).
     */
    public function currency(): string
    {
        return strtoupper((string) $this->settings->get('currency_code', 'INR'));
    }

    /**
     * Create an order for a server-decided amount.
     *
     * @param  float  $amount  in major units, converted to minor units here
     * @return array{id:string,amount:int,currency:string}|null
     */
    public function createOrder(float $amount, string $receipt): ?array
    {
        $minor = (int) round($amount * 100);

        if ($minor < 100) {
            return null;
        }

        try {
            $response = Http::withBasicAuth($this->keyId(), $this->secret())
                ->timeout(20)
                ->asJson()
                ->post(self::API.'/orders', [
                    'amount' => $minor,
                    'currency' => $this->currency(),
                    'receipt' => $receipt,
                    'payment_capture' => 1,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Razorpay unreachable while creating an order', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! $response->json('id')) {
            Log::warning('Razorpay rejected the order', ['body' => $response->body()]);

            return null;
        }

        return [
            'id' => (string) $response->json('id'),
            'amount' => $minor,
            'currency' => $this->currency(),
        ];
    }

    /**
     * Confirm a payment really happened, for the amount we expected.
     *
     * @param  int  $expectedMinor  the amount in minor units recorded at order time
     * @return array{ok:bool,error:?string,payment_id:?string}
     */
    public function verify(string $orderId, string $paymentId, string $signature, int $expectedMinor): array
    {
        // 1. Signature — proves the callback came from Razorpay.
        $expectedSignature = hash_hmac('sha256', $orderId.'|'.$paymentId, $this->secret());

        if (! hash_equals($expectedSignature, $signature)) {
            Log::warning('Razorpay signature mismatch', ['order' => $orderId, 'payment' => $paymentId]);

            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        // 2. Ask Razorpay directly; a valid signature still is not proof of capture.
        try {
            $response = Http::withBasicAuth($this->keyId(), $this->secret())
                ->timeout(20)
                ->get(self::API.'/payments/'.$paymentId);
        } catch (ConnectionException $e) {
            Log::warning('Razorpay unreachable while verifying', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        $status = (string) $response->json('status');
        $paidMinor = (int) $response->json('amount');
        $paidOrder = (string) $response->json('order_id');

        if ($status !== 'captured') {
            return ['ok' => false, 'error' => 'That payment has not completed.', 'payment_id' => null];
        }

        // 3. The payment must belong to our order and match our amount, so a
        //    cheap payment cannot be replayed against an expensive top-up.
        if ($paidOrder !== $orderId || $paidMinor !== $expectedMinor) {
            Log::warning('Razorpay amount/order mismatch', [
                'expected_order' => $orderId, 'paid_order' => $paidOrder,
                'expected' => $expectedMinor, 'paid' => $paidMinor,
            ]);

            return ['ok' => false, 'error' => 'That payment did not match your top-up.', 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => $paymentId];
    }
}
