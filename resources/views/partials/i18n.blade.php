{{--
    Runtime strings for the JS components (trackers, payment runners, pickers).
    Keys are the English source strings; resources/js/i18n.js falls back to
    the key itself, so a missing entry shows English rather than breaking.
--}}
@php
    $jsStrings = [
        'Looking for a driver',
        'Driver on the way',
        'On the way to your destination',
        'Trip completed',
        'Trip cancelled',
        'Your driver',
        'Pickup',
        'Drop-off',
        'Locating…',
        'Could not load the payment window. Check your connection.',
        'Could not price this trip. Please try again.',
        'Could not send the alert.',
        'Could not start the payment. Please try again.',
        'Could not start the payment.',
        'That payment did not go through.',
        'That payment has not completed.',
        'That payment method is not supported in the browser yet.',
        'The card form could not load. Please check the Stripe keys in the admin panel.',
        'We could not confirm that payment.',
        'Wallet top-up',
        'Wallet topped up.',
        'Parcel delivery',
        'Vehicle rental',
        'Ride payment',
        'Could not send that message.',
        'Card payment',
        'Pay',
        'Pay with PayPal',
        'Total',
        'Ride alerts enabled.',
        'Could not enable notifications.',
        'Please allow pop-ups to use this payment method.',
        'Confirm the payment on your phone.',
        'Waiting for payment confirmation…',
        'Could not sign you in. Please try again.',
        'Could not create your account. Please try again.',
        'Enter your mobile number first.',
        'That phone number does not look right.',
        'Too many attempts — please wait a little and try again.',
        'That code is not correct.',
        'That code has expired. Send a new one.',
        'Enter your name.',
        'Enter your email address.',
        'Enter a password (at least 6 characters).',
        'Passwords do not match.',
    ];
@endphp
<script>
    window.__i18n = @json(collect($jsStrings)->mapWithKeys(fn ($s) => [$s => __($s)]));
</script>
