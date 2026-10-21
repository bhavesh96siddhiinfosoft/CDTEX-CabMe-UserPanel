<?php

namespace App\Services;

use App\Support\FirebaseCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FCM HTTP v1 sender, authenticated with the service account the admin
 * uploaded (see FirebaseCredentials). The admin backend already pushes ride
 * events to user_app.fcm_id itself — this service exists for pushes the
 * panel originates (and for verifying the credentials actually work).
 */
class FcmService
{
    private const TOKEN_CACHE = 'cabme.fcm.oauth';

    public function __construct(private readonly FirebaseCredentials $credentials)
    {
    }

    public function configured(): bool
    {
        return $this->credentials->get() !== null;
    }

    /**
     * Send a notification to one device token.
     *
     * @return array{ok:bool,error:?string}
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): array
    {
        $account = $this->credentials->get();

        if (! $account) {
            return ['ok' => false, 'error' => 'Firebase is not configured in the admin panel.'];
        }

        $token = $this->oauthToken($account);

        if (! $token) {
            return ['ok' => false, 'error' => 'Could not authenticate with Firebase.'];
        }

        $message = [
            'token' => $deviceToken,
            'notification' => ['title' => $title, 'body' => $body],
            'webpush' => [
                'fcm_options' => ['link' => url('/app')],
            ],
        ];

        // An empty PHP array would serialize as [] and FCM requires a map.
        if ($data !== []) {
            $message['data'] = array_map('strval', $data);
        }

        try {
            $response = Http::withToken($token)->timeout(20)->post(
                'https://fcm.googleapis.com/v1/projects/'.$account['project_id'].'/messages:send',
                ['message' => $message],
            );
        } catch (ConnectionException $e) {
            Log::warning('FCM unreachable', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'Could not reach Firebase.'];
        }

        if (! $response->successful()) {
            Log::warning('FCM rejected the message', ['body' => substr($response->body(), 0, 300)]);

            return ['ok' => false, 'error' => (string) $response->json('error.message', 'FCM rejected the message.')];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * OAuth2 access token via a self-signed service-account JWT — no SDK,
     * just RS256 over the private key from the admin's JSON.
     */
    private function oauthToken(array $account): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();

        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        $payload = $encode(['alg' => 'RS256', 'typ' => 'JWT'])
            .'.'.$encode([
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]);

        $key = openssl_pkey_get_private($account['private_key']);

        if (! $key || ! openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            Log::warning('Could not sign the Firebase JWT — bad private key?');

            return null;
        }

        $jwt = $payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        try {
            $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Google OAuth unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $token = (string) $response->json('access_token');

        if (! $response->successful() || $token === '') {
            Log::warning('Google OAuth rejected the service account', ['body' => substr($response->body(), 0, 300)]);

            return null;
        }

        Cache::put(self::TOKEN_CACHE, $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }
}
