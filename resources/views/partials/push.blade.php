{{--
    Firebase browser config. Every value here is public-safe (web api key,
    app id, VAPID public key, sender id).

    __firebase — for ALL visitors: powers phone-OTP / Google sign-in on the
    auth pages (resources/js/components/firebase-auth.js).
    __push — signed-in riders only: adds the web-push half
    (resources/js/push.js).
--}}
@php
    $firebase = config('cabme.firebase');
    $account = ($firebase['api_key'] && $firebase['app_id'])
        ? app(\App\Support\FirebaseCredentials::class)->get()
        : null;

    $firebaseConfig = $account ? [
        'apiKey' => $firebase['api_key'],
        'appId' => $firebase['app_id'],
        'projectId' => $account['project_id'],
        // signInWithPopup (Google) refuses to run without an authDomain.
        'authDomain' => $account['project_id'].'.firebaseapp.com',
        'senderId' => $account['sender_id'],
    ] : null;

    $pushConfig = ($firebaseConfig && $firebase['vapid_key'] && $rider->check()) ? array_merge($firebaseConfig, [
        'vapidKey' => $firebase['vapid_key'],
        'subscribeUrl' => route('app.push.subscribe'),
    ]) : null;
@endphp

@if ($firebaseConfig)
    <script>window.__firebase = @json($firebaseConfig);</script>
@endif

@if ($pushConfig)
    <script>window.__push = @json($pushConfig);</script>
@endif
