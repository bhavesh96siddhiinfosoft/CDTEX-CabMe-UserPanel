<?php

namespace App\Services\Payments;

use App\Services\Cabme\CatalogApi;
use App\Support\CabmeSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stripe, charged server-side via PaymentIntents.
 *
 * Credentials come from the ADMIN's payment settings (the "Strip" bucket:
 * `secret_key` + `clientpublishableKey`), the same single source the apps
 * use. Only the publishable key ever reaches the browser.
 *
 * Note: the admin's own v1/payments/stripepaymentintent endpoint multiplies
 * the amount by 1000 (a 10× overcharge bug) and takes the secret from the
 * request — this implementation replaces it, converting to minor units
 * correctly and verifying every intent server-side before money is credited.
 */
class StripeGateway
{
    private const API = 'https://api.stripe.com/v1';

    private const CREDS_CACHE = 'cabme.stripe.creds';

    /** Currencies Stripe treats as zero-decimal (no minor unit ×100). */
    private const ZERO_DECIMAL = ['JPY', 'KRW', 'VND', 'CLP', 'PYG', 'RWF', 'UGX', 'XAF', 'XOF'];

    /** @var array{secret:string,publishable:string}|null */
    private ?array $credentials = null;

    public function __construct(
        private readonly CatalogApi $catalog,
        private readonly CabmeSettings $settings,
    ) {
    }

    public function configured(): bool
    {
        $creds = $this->credentials();

        return $creds['secret'] !== '' && $creds['publishable'] !== '';
    }

    public function publishableKey(): string
    {
        return $this->credentials()['publishable'];
    }

    private function secret(): string
    {
        return $this->credentials()['secret'];
    }

    /** @return array{secret:string,publishable:string} */
    private function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        return $this->credentials = Cache::remember(self::CREDS_CACHE, 300, function () {
            $body = $this->catalog->paymentMethods()->raw;

            foreach ($body as $bucket) {
                if (! is_array($bucket)) {
                    continue;
                }

                foreach (array_is_list($bucket) ? $bucket : [$bucket] as $row) {
                    $label = strtolower((string) ($row['libelle'] ?? ''));

                    // The admin spells the bucket "Strip", libelle "Stripe".
                    if (! is_array($row) || ! str_contains($label, 'strip')) {
                        continue;
                    }

                    if (($row['isEnabled'] ?? '') !== 'true') {
                        continue;
                    }

                    $secret = trim((string) ($row['secret_key'] ?? ''));

                    // The admin stores TWO publishable keys (`key` and
                    // `clientpublishableKey`) and they have been observed to
                    // belong to DIFFERENT Stripe accounts. A publishable key
                    // from the wrong account cannot load the intent our secret
                    // created — the Payment Element then mounts nothing. Pick
                    // the candidate whose account fragment matches the secret.
                    $candidates = array_values(array_filter([
                        trim((string) ($row['clientpublishableKey'] ?? '')),
                        trim((string) ($row['key'] ?? '')),
                    ], fn ($k) => str_starts_with($k, 'pk_')));

                    $account = fn (string $k) => substr(preg_replace('/^(pk|sk)_(test|live)_/', '', $k), 0, 16);

                    $publishable = '';
                    foreach ($candidates as $candidate) {
                        if ($secret !== '' && $account($candidate) === $account($secret)) {
                            $publishable = $candidate;
                            break;
                        }
                    }

                    // No account match — take the first pk_ and let loaderror
                    // surface it visibly rather than guessing further.
                    $publishable = $publishable !== '' ? $publishable : ($candidates[0] ?? '');

                    return ['secret' => $secret, 'publishable' => $publishable];
                }
            }

            return ['secret' => '', 'publishable' => ''];
        });
    }

    public function currency(): string
    {
        return strtoupper((string) $this->settings->get('currency_code', 'INR'));
    }

    private function toMinor(float $amount): int
    {
        return in_array($this->currency(), self::ZERO_DECIMAL, true)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }

    /**
     * Create a PaymentIntent for a server-decided amount.
     *
     * @return array{id:string,client_secret:string,amount:int,currency:string}|null
     */
    public function createIntent(float $amount, string $reference): ?array
    {
        $minor = $this->toMinor($amount);

        if ($minor < 1) {
            return null;
        }

        try {
            $response = Http::withToken($this->secret())
                ->asForm()
                ->timeout(20)
                ->post(self::API.'/payment_intents', [
                    'amount' => $minor,
                    'currency' => strtolower($this->currency()),
                    'automatic_payment_methods[enabled]' => 'true',
                    'automatic_payment_methods[allow_redirects]' => 'never',
                    'metadata[cabme_ref]' => $reference,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Stripe unreachable while creating an intent', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! $response->json('id')) {
            Log::warning('Stripe rejected the intent', ['body' => substr($response->body(), 0, 300)]);

            return null;
        }

        return [
            'id' => (string) $response->json('id'),
            'client_secret' => (string) $response->json('client_secret'),
            'amount' => $minor,
            'currency' => $this->currency(),
        ];
    }

    /**
     * Confirm a payment really succeeded, for the amount and reference we
     * recorded — retrieved from Stripe directly, never trusted from the
     * browser.
     *
     * @return array{ok:bool,error:?string,payment_id:?string}
     */
    public function verify(string $intentId, int $expectedMinor, string $expectedReference): array
    {
        try {
            $response = Http::withToken($this->secret())
                ->timeout(20)
                ->get(self::API.'/payment_intents/'.$intentId);
        } catch (ConnectionException $e) {
            Log::warning('Stripe unreachable while verifying', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => __('We could not verify that payment.'), 'payment_id' => null];
        }

        $status = (string) $response->json('status');
        $paidMinor = (int) $response->json('amount_received');
        $reference = (string) $response->json('metadata.cabme_ref');

        if ($status !== 'succeeded') {
            return ['ok' => false, 'error' => 'That payment has not completed.', 'payment_id' => null];
        }

        if ($paidMinor !== $expectedMinor || $reference !== $expectedReference) {
            Log::warning('Stripe amount/reference mismatch', [
                'intent' => $intentId, 'expected' => $expectedMinor, 'paid' => $paidMinor,
            ]);

            return ['ok' => false, 'error' => 'That payment did not match this order.', 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => $intentId];
    }
}
