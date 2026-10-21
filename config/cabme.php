<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CabMe admin API
    |--------------------------------------------------------------------------
    | This panel owns no database. Every read and write goes through the
    | cabme-admin REST API at {base_url}/api/v1/*. The api_key must match the
    | admin's APP_KEY exactly — its ApiKeyAuth middleware compares them
    | verbatim, including the "base64:" prefix.
    */

    'base_url' => rtrim(env('CABME_API_URL', 'http://localhost'), '/'),

    'api_key' => env('CABME_API_KEY', ''),

    'timeout' => (int) env('CABME_API_TIMEOUT', 30),

    'retries' => (int) env('CABME_API_RETRIES', 2),

    // Log every outbound API call + normalised result. Noisy; dev only.
    'debug' => (bool) env('CABME_API_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Settings cache
    |--------------------------------------------------------------------------
    | v1/settings/ carries currency, map provider/key and active_services.
    | It changes rarely, so it is cached rather than fetched per request.
    */

    'settings_ttl' => (int) env('CABME_SETTINGS_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Live tracking
    |--------------------------------------------------------------------------
    | The admin has no ride broadcast channels, so the active-ride view polls
    | v1/user-recent-ride/ on this interval (milliseconds).
    */

    'poll_interval' => (int) env('CABME_POLL_INTERVAL', 5000),

    // Payment gateway credentials deliberately have NO entries here: they come
    // from the admin panel's payment settings (v1/payment-settings/), fetched
    // server-to-server — the same single source the mobile apps use. They are
    // used server-side only and never sent to a browser.

    /*
    |--------------------------------------------------------------------------
    | Firebase web push
    |--------------------------------------------------------------------------
    | The SERVER credentials (service-account JSON + sender id) come from the
    | admin panel (Settings → Notifications) via v1/firebase-credentials/.
    | The three values below are the Firebase WEB APP half, which the admin
    | does not store: Project settings → General → Your apps (web) for api
    | key/app id, and Cloud Messaging → Web Push certificates for the VAPID
    | key. All three are public-safe browser values. Push stays off until
    | they are filled in.
    */

    'firebase' => [
        'vapid_key' => env('FIREBASE_VAPID_KEY', ''),
        'api_key' => env('FIREBASE_API_KEY', ''),
        'app_id' => env('FIREBASE_APP_ID', ''),
    ],

];
