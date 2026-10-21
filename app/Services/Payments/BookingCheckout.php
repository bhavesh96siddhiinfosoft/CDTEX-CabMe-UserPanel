<?php

namespace App\Services\Payments;

use App\Services\Cabme\AuthApi;
use App\Services\Cabme\CatalogApi;
use App\Support\PaymentMethods;
use App\Support\Rider;
use Illuminate\Support\Str;

/**
 * Resolves how a pay-first booking (parcel, rental) is actually paid.
 *
 * The browser only ever names a payment method id; the money side is settled
 * here, mirroring what the admin's own controllers do with each method:
 *
 *  - cash     nothing to charge — the driver collects. Local reference id.
 *  - wallet   the ADMIN debits the rider at register (parcel only), so the
 *             panel's job is just to confirm the balance covers the fare
 *             before letting the booking through.
 *  - gateway  the rider must have completed a server-verified charge for this
 *             exact amount first (PayController stores it in the session);
 *             its gateway payment id becomes the booking's transaction id.
 *
 * @phpstan-type Resolution array{ok:bool, transaction_id:?string, error:?string}
 */
class BookingCheckout
{
    /** Session key holding the last server-verified gateway charge. */
    public const VERIFIED = 'booking_payment_verified';

    public function __construct(
        private readonly CatalogApi $catalog,
        private readonly AuthApi $auth,
        private readonly Rider $rider,
    ) {
    }

    /**
     * @param  string  $context  'parcel' or 'rental' — decides which methods are legal
     * @return array{ok:bool, transaction_id:?string, error:?string}
     */
    public function resolve(string $context, int $methodId, float $amount): array
    {
        $method = collect($this->catalog->enabledPaymentMethods($context))
            ->firstWhere('id', $methodId);

        if (! $method) {
            return $this->failure(__('That payment method is not available for this booking.'));
        }

        return match ($method['kind']) {
            'offline' => $this->ok('CASH-'.Str::upper(Str::random(12))),
            'wallet' => $this->wallet($amount),
            default => $this->gateway($methodId, $amount),
        };
    }

    /**
     * Wallet: the admin performs the debit inside its register call, so only
     * the balance is checked here — debiting here too would charge twice.
     */
    private function wallet(float $amount): array
    {
        $this->auth->refreshProfile();

        if ($this->rider->balance() < $amount) {
            return $this->failure(__('Your wallet balance (:balance) does not cover this booking.', [
                'balance' => number_format($this->rider->balance(), 2),
            ]));
        }

        return $this->ok('WALLET-'.Str::upper(Str::random(12)));
    }

    /**
     * Gateway: PayController::verify() stored the confirmed charge in the
     * session. It must exist, match the amount, and is consumed on use so one
     * charge can never cover two bookings.
     */
    private function gateway(int $methodId, float $amount): array
    {
        $verified = session()->pull(self::VERIFIED);

        if (! $verified) {
            return $this->failure(__('Please complete the payment first.'));
        }

        if ((int) $verified['method_id'] !== $methodId
            || abs((float) $verified['amount'] - $amount) > 0.01) {
            return $this->failure(__('That payment does not match this booking. Please pay again.'));
        }

        return $this->ok((string) $verified['transaction_id']);
    }

    private function ok(string $transactionId): array
    {
        return ['ok' => true, 'transaction_id' => $transactionId, 'error' => null];
    }

    private function failure(string $error): array
    {
        return ['ok' => false, 'transaction_id' => null, 'error' => $error];
    }
}
