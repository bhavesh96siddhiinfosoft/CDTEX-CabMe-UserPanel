<?php

namespace App\Support;

/**
 * Charge currency per gateway — ported from goride-web's payment-cfg, per
 * the user's direction to mirror that setup.
 *
 * The admin's global currency is DISPLAY-only. Region-locked gateways
 * (Midtrans IDR, PayFast ZAR, …) are charged in their own
 * currency with the same numeric amount; gateways with a supported list use
 * the display currency when it is in the list, else their default.
 */
class GatewayCurrency
{
    private const RULES = [
        'stripe' => ['supported' => ['USD', 'INR', 'EUR', 'GBP', 'CAD', 'AUD', 'AED', 'SGD'], 'default' => 'USD'],
        // Razorpay accepts international currencies on enabled accounts —
        // this panel's driver charges the display currency, and real USD
        // test charges have gone through on this very account.
        'razorpay' => ['supported' => ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD'], 'default' => 'INR'],
        // USD dropped: real Paystack merchants are NGN-home and reject it
        // ("Currency not supported by merchant").
        'paystack' => ['supported' => ['NGN', 'GHS', 'ZAR', 'KES'], 'default' => 'NGN'],
        'flutterwave' => ['only' => 'NGN'],
        'cashfree' => ['only' => 'INR'],
        'phonepe' => ['only' => 'INR'],
        'xendit' => ['only' => 'IDR'],
        'midtrans' => ['only' => 'IDR'],
        'payfast' => ['only' => 'ZAR'],
        'paypal' => ['supported' => ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'JPY'], 'default' => 'USD'],
        'mercadopago' => ['supported' => ['ARS', 'BRL', 'MXN', 'CLP', 'COP', 'PEN', 'UYU'], 'default' => 'ARS'],
        'orangepay' => ['only' => 'XOF'],
        'mtnmomo' => ['default' => 'EUR'],
        'instamojo' => ['only' => 'INR'],
        'paymongo' => ['only' => 'PHP'],
        'foloosi' => ['only' => 'AED'],
    ];

    public static function chargeCurrency(string $gateway, string $displayCurrency): string
    {
        $rule = self::RULES[$gateway] ?? null;
        $display = strtoupper(trim($displayCurrency));

        if (! $rule) {
            return $display !== '' ? $display : 'USD';
        }

        if (isset($rule['only'])) {
            return $rule['only'];
        }

        if ($display !== '' && in_array($display, $rule['supported'] ?? [], true)) {
            return $display;
        }

        return $rule['default'] ?? 'USD';
    }

    /**
     * The numeric amount to charge, in the gateway's charge currency
     * (goride rule: IDR gateways charge display × 1000).
     */
    public static function chargeAmount(string $gateway, float $displayAmount): float
    {
        if (self::chargeCurrency($gateway, '') === 'IDR'
            && in_array($gateway, ['xendit', 'midtrans'], true)) {
            return (float) round($displayAmount * 1000);
        }

        return round($displayAmount, 2);
    }
}
