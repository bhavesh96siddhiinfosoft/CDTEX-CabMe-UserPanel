<?php

namespace App\Services\Cabme;

use App\Support\ApiResult;
use App\Support\Rider;

/** Rider wallet: balance history and top-ups. */
class WalletApi
{
    public function __construct(
        private readonly CabmeClient $client,
        private readonly Rider $rider,
    ) {
    }

    public function history(): ApiResult
    {
        return $this->client->auth('POST', 'v1/get-wallet-history/', [
            'user_id' => $this->rider->id(),
            'user_type' => 'customer',
        ]);
    }

    /**
     * Credit the wallet after a gateway has confirmed payment. Only ever call
     * this with a transaction id the server has verified — the admin records
     * the amount as given without re-checking it with the gateway.
     */
    public function credit(float $amount, int $paymentMethodId, string $transactionId, string $note = 'Wallet top-up'): ApiResult
    {
        return $this->client->auth('POST', 'v1/amount/', [
            'user_id' => $this->rider->id(),
            'user_type' => 'customer',
            'amount' => (int) $amount,
            'payment_method' => $paymentMethodId,
            'is_credited' => 1,
            'note' => $note,
            'transaction_id' => $transactionId,
        ]);
    }
}
