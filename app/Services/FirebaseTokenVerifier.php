<?php

namespace App\Services;

use App\Support\FirebaseCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies Firebase Authentication ID tokens server-side.
 *
 * Phone-OTP and Google sign-in happen in the BROWSER through Firebase; the
 * browser then hands us the resulting ID token. Nothing else it claims is
 * trusted: the token's RS256 signature is checked against Google's published
 * securetoken certificates, and issuer/audience/expiry must match our
 * project. The verified claims (phone_number, email, name) are the only
 * identity source the panel acts on.
 */
class FirebaseTokenVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    private const CERTS_CACHE = 'cabme.firebase.securetoken.certs';

    public function __construct(private readonly FirebaseCredentials $credentials)
    {
    }

    /**
     * @return array|null  the token claims, or null when anything fails
     */
    public function verify(string $idToken): ?array
    {
        $projectId = $this->credentials->get()['project_id'] ?? '';

        if ($projectId === '') {
            return null;
        }

        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            return null;
        }

        $decode = fn (string $part) => json_decode(
            base64_decode(strtr($part, '-_', '+/')) ?: '',
            true,
        );

        $header = $decode($parts[0]);
        $claims = $decode($parts[1]);

        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'RS256') {
            return null;
        }

        $certificate = $this->certificates()[(string) ($header['kid'] ?? '')] ?? null;

        if (! $certificate) {
            return null;
        }

        $publicKey = openssl_pkey_get_public($certificate);
        $signature = base64_decode(strtr($parts[2], '-_', '+/'));

        if (! $publicKey
            || openssl_verify($parts[0].'.'.$parts[1], $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            Log::warning('Firebase ID token failed signature verification');

            return null;
        }

        $now = time();

        $valid = ($claims['aud'] ?? '') === $projectId
            && ($claims['iss'] ?? '') === 'https://securetoken.google.com/'.$projectId
            && (int) ($claims['exp'] ?? 0) > $now
            && (int) ($claims['iat'] ?? PHP_INT_MAX) <= $now + 300
            && ($claims['sub'] ?? '') !== '';

        if (! $valid) {
            Log::warning('Firebase ID token claims rejected', [
                'aud' => $claims['aud'] ?? null, 'iss' => $claims['iss'] ?? null,
            ]);

            return null;
        }

        return $claims;
    }

    /** @return array<string,string> kid => x509 PEM */
    private function certificates(): array
    {
        $cached = Cache::get(self::CERTS_CACHE);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $response = Http::timeout(20)->get(self::CERTS_URL);
        } catch (\Throwable $e) {
            Log::warning('Could not fetch Google securetoken certificates', ['error' => $e->getMessage()]);

            return [];
        }

        $certs = $response->json();

        if (! $response->successful() || ! is_array($certs)) {
            return [];
        }

        Cache::put(self::CERTS_CACHE, $certs, 3600);

        return $certs;
    }
}
