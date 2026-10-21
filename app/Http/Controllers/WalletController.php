<?php

namespace App\Http\Controllers;

use App\Services\Cabme\AuthApi;
use App\Services\Cabme\CatalogApi;
use App\Services\Cabme\WalletApi;
use App\Support\Rider;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletApi $wallet,
        private readonly CatalogApi $catalog,
        private readonly AuthApi $auth,
        private readonly Rider $rider,
    ) {
    }

    public function index(\Illuminate\Http\Request $request)
    {
        // The balance lives on the admin's user row and changes with every trip
        // and top-up, so re-read it rather than trusting the login snapshot.
        $this->auth->refreshProfile();

        $history = $this->wallet->history();
        $entries = $this->filterRows(
            $history->ok ? $history->list() : [],
            ['id', 'note', 'payment_method', 'transaction_id'],
            ['created_at'],
            ['amount'],
        );

        $paged = $this->paginate($entries, route('app.wallet'), 10, 'history');

        return view('app.wallet', [
            'balance' => $this->rider->balance(),
            'history' => $paged,
            'paymentMethods' => $this->catalog->enabledPaymentMethods('topup'),
            'gatewaysReady' => $this->catalog->enabledPaymentMethods('topup') !== [],
            // "No result found" is how the admin reports an empty ledger.
            'error' => $history->failed() && ! $history->isEmptyResult()
                ? $history->messageOr(__('Could not load your wallet.'))
                : null,
        ]);
    }

    // Top-ups run through TopupController (order → gateway → verify → credit).
    // A direct credit endpoint used to live here; it trusted the browser's
    // amount, so it was removed rather than kept "just in case".
}
