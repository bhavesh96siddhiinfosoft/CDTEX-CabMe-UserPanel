<?php

namespace App\Support;

/**
 * What each payment method actually is, and where it may be offered.
 *
 * The admin enables gateways globally, but "enabled" is not the same as
 * "usable here": cash cannot pay for a wallet top-up, the wallet cannot top
 * itself up, and a gateway with no credentials configured will fail the moment
 * a rider picks it. This narrows the admin's list to what will genuinely work
 * in a given context.
 */
class PaymentMethods
{
    /** Paid to the driver in person — never a valid online payment. */
    public const OFFLINE = ['cash'];

    /** Settled from the rider's CabMe balance rather than a gateway. */
    public const INTERNAL = ['wallet', 'my wallet'];

    /**
     * Withdrawn from the platform. A method matching one of these is never
     * offered, in any context, even while the admin still has a row for it
     * — the integration is gone, so picking it could only fail.
     */
    public const RETIRED = ['paytm'];

    /**
     * Gateways this panel can actually charge through, server-side. Grows as
     * integrations land — a method must never be offered in a pay-first
     * context unless picking it leads to a real charge.
     */
    private const IMPLEMENTED_GATEWAYS = [
        'razorpay', 'strip', 'paystack', 'flutter', 'paypal',
        'payfast', 'mercado', 'midtrans', 'orange',
        'xendit', 'cashfree', 'phonepe', 'mtn',
        'instamojo', 'paymongo', 'foloosi',
    ];

    /**
     * Gateways whose checkout is a hosted page: the browser opens a popup
     * window BEFORE starting the charge (popup blockers require the open to
     * happen inside the click), then navigates it to the gateway.
     */
    private const REDIRECT_GATEWAYS = [
        'payfast', 'mercado', 'midtrans', 'orange', 'xendit', 'cashfree', 'phonepe',
        'instamojo', 'paymongo',
        // Hosted checkout: the inline popup force-defaults NGN, which most
        // merchant accounts reject.
        'paystack',
    ];

    /** How the browser runs this method's checkout: redirect popup or in-page. */
    public static function browserFlow(string $label): string
    {
        return self::matches($label, self::REDIRECT_GATEWAYS) ? 'redirect' : 'inline';
    }

    /**
     * Which driver a payment-method label belongs to — the single source for
     * GatewayCharge routing and the charge-currency badge. Most specific
     * fragments first: several labels share prefixes (PayPal, PayStack,
     * PayFast, PayMongo).
     */
    public static function driverKey(string $label): ?string
    {
        $key = strtolower($label);

        return match (true) {
            str_contains($key, 'razorpay') => 'razorpay',
            str_contains($key, 'paystack') => 'paystack',
            str_contains($key, 'strip') => 'stripe',
            str_contains($key, 'flutter') => 'flutterwave',
            str_contains($key, 'paypal') => 'paypal',
            str_contains($key, 'payfast') => 'payfast',
            str_contains($key, 'mercado') => 'mercadopago',
            str_contains($key, 'midtrans') => 'midtrans',
            str_contains($key, 'orange') => 'orangepay',
            str_contains($key, 'xendit') => 'xendit',
            str_contains($key, 'cashfree') => 'cashfree',
            str_contains($key, 'phonepe') => 'phonepe',
            str_contains($key, 'mtn') => 'mtnmomo',
            str_contains($key, 'instamojo') => 'instamojo',
            str_contains($key, 'paymongo') => 'paymongo',
            str_contains($key, 'foloosi') => 'foloosi',
            default => null,
        };
    }

    public static function isImplemented(string $label): bool
    {
        return self::matches($label, self::IMPLEMENTED_GATEWAYS);
    }

    /** Credential fields that indicate a gateway is actually configured. */
    private const CREDENTIAL_FIELDS = [
        'key', 'secret_key', 'public_key', 'clientpublishableKey',
        'merchant_Id', 'merchant_key', 'encryption_key',
    ];

    public static function isOffline(string $label): bool
    {
        // Whole-word match: "Cashfree" is a GATEWAY, not cash — a substring
        // match here once classified it as offline, i.e. book-without-paying.
        foreach (self::OFFLINE as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $label)) {
                return true;
            }
        }

        return false;
    }

    /** Withdrawn from the platform — never offer it. */
    public static function isRetired(string $label): bool
    {
        return self::matches($label, self::RETIRED);
    }

    public static function isInternal(string $label): bool
    {
        return self::matches($label, self::INTERNAL);
    }

    /** A real external gateway — i.e. neither cash nor the in-app wallet. */
    public static function isGateway(string $label): bool
    {
        return ! self::isOffline($label) && ! self::isInternal($label);
    }

    /**
     * Whether the admin has stored credentials for this gateway. Offering one
     * without them just sends the rider into a dead end.
     */
    public static function isConfigured(array $row, string $label): bool
    {
        if (! self::isGateway($label)) {
            return true;    // cash and wallet need no credentials
        }

        foreach (self::CREDENTIAL_FIELDS as $field) {
            if (strlen(trim((string) ($row[$field] ?? ''))) > 6) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $label, array $needles): bool
    {
        $key = strtolower(trim($label));

        foreach ($needles as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
