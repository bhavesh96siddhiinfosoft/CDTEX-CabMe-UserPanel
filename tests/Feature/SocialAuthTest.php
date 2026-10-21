<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phone-OTP / Google sign-in trusts ONLY the Firebase ID token: signature
 * against Google's certs, our project as audience, and identity (phone or
 * email) taken from the verified claims — never from the request body.
 */
class SocialAuthTest extends TestCase
{
    private static $keyPair = null;

    private static string $certificate = '';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);

        if (! self::$keyPair) {
            // Windows PHP finds no default openssl.cnf; point at the one
            // shipped beside the PHP binary so key generation works.
            $config = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
            self::$keyPair = openssl_pkey_new(array_filter([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config' => is_file($config) ? $config : null,
            ]));
            // openssl_pkey_get_public accepts a bare public-key PEM as well
            // as Google's x509 certs, so the fake cert endpoint serves one —
            // building a real x509 needs an openssl.cnf Windows PHP lacks.
            self::$certificate = openssl_pkey_get_details(self::$keyPair)['key'];
        }
    }

    private function fake(array $extra = []): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge([
            'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com' => Http::response([
                'test-kid' => self::$certificate,
            ]),
            'https://admin.test/api/v1/firebase-credentials/' => Http::response([
                'success' => 'success',
                'data' => [
                    'sender_id' => '527452785533',
                    'service_json_data' => json_encode([
                        'project_id' => 'cabme-test',
                        'client_email' => 'svc@cabme-test.iam.gserviceaccount.com',
                        'private_key' => 'unused-here',
                    ]),
                ],
            ]),
            'https://admin.test/api/v1/settings/' => Http::response(['success' => 'success', 'data' => []]),
        ], $extra, [
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]));
    }

    /** A properly signed Firebase-style ID token for our fake project. */
    private function token(array $claims, ?string $kid = 'test-kid'): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        $now = time();
        $payload = $encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid])
            .'.'.$encode(array_merge([
                'iss' => 'https://securetoken.google.com/cabme-test',
                'aud' => 'cabme-test',
                'iat' => $now - 10,
                'exp' => $now + 3600,
                'sub' => 'firebase-uid-1',
            ], $claims));

        openssl_sign($payload, $signature, self::$keyPair, OPENSSL_ALGO_SHA256);

        return $payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    private function adminProfile(): array
    {
        return [
            'success' => 'success', 'error' => null, 'message' => 'successfully',
            'data' => ['id' => '77', 'prenom' => 'Asha', 'nom' => 'Rider',
                'email' => 'asha@example.com', 'accesstoken' => 'tok-social-1'],
        ];
    }

    public function test_a_verified_phone_token_signs_the_rider_in(): void
    {
        $this->fake([
            'https://admin.test/api/v1/profilebyphone/' => Http::response($this->adminProfile()),
        ]);

        $this->postJson('/auth/firebase', [
            'id_token' => $this->token(['phone_number' => '+919876543210']),
            'phone' => '9876543210',
            'country_code' => '+91',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('tok-social-1', session('cabme_rider.token'));
    }

    public function test_a_phone_split_that_does_not_match_the_token_is_rejected(): void
    {
        // The OTP verified +919876543210, but the browser claims a DIFFERENT
        // number for the admin lookup — that must never pass.
        $this->fake();

        $this->postJson('/auth/firebase', [
            'id_token' => $this->token(['phone_number' => '+919876543210']),
            'phone' => '9999999999',
            'country_code' => '+91',
        ])->assertStatus(422);

        $this->assertNull(session('cabme_rider'));
    }

    public function test_a_token_signed_by_an_unknown_key_is_rejected(): void
    {
        $this->fake();

        $config = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        $foreign = openssl_pkey_new(array_filter([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => is_file($config) ? $config : null,
        ]));
        $encode = fn (array $p) => rtrim(strtr(base64_encode(json_encode($p)), '+/', '-_'), '=');
        $now = time();
        $payload = $encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'test-kid'])
            .'.'.$encode(['iss' => 'https://securetoken.google.com/cabme-test', 'aud' => 'cabme-test',
                'iat' => $now, 'exp' => $now + 3600, 'sub' => 'x', 'phone_number' => '+919876543210']);
        openssl_sign($payload, $signature, $foreign, OPENSSL_ALGO_SHA256);

        $this->postJson('/auth/firebase', [
            'id_token' => $payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
            'phone' => '9876543210', 'country_code' => '+91',
        ])->assertStatus(422);
    }

    public function test_a_token_for_another_project_is_rejected(): void
    {
        $this->fake();

        $this->postJson('/auth/firebase', [
            'id_token' => $this->token([
                'phone_number' => '+919876543210',
                'aud' => 'someone-elses-project',
                'iss' => 'https://securetoken.google.com/someone-elses-project',
            ]),
            'phone' => '9876543210', 'country_code' => '+91',
        ])->assertStatus(422);
    }

    public function test_an_unknown_phone_asks_for_registration(): void
    {
        $this->fake([
            'https://admin.test/api/v1/profilebyphone/' => Http::response([
                'success' => 'Failed', 'error' => 'User not found',
            ]),
        ]);

        $this->postJson('/auth/firebase', [
            'id_token' => $this->token(['phone_number' => '+919876543210']),
            'phone' => '9876543210', 'country_code' => '+91',
        ])->assertOk()->assertJson(['register' => true, 'mode' => 'phone']);
    }

    public function test_phone_registration_creates_the_account_and_signs_in(): void
    {
        $this->fake([
            'https://admin.test/api/v1/user/' => Http::response($this->adminProfile()),
        ]);

        $this->postJson('/auth/firebase/register', [
            'id_token' => $this->token(['phone_number' => '+919876543210']),
            'phone' => '9876543210', 'country_code' => '+91',
            'firstname' => 'Asha', 'lastname' => 'Rider', 'email' => 'asha@example.com',
        ])->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'v1/user/')
                && $request['login_type'] === 'phoneNumber'
                && $request['phone'] === '9876543210'
                && strlen((string) $request['password']) >= 6;
        });

        $this->assertSame('tok-social-1', session('cabme_rider.token'));
    }

    public function test_google_registration_takes_the_email_from_the_token_not_the_form(): void
    {
        $this->fake([
            'https://admin.test/api/v1/user/' => Http::response($this->adminProfile()),
        ]);

        $this->postJson('/auth/firebase/register', [
            'id_token' => $this->token(['email' => 'real@gmail.com', 'name' => 'Asha Rider']),
            'firstname' => 'Asha', 'lastname' => 'Rider',
            'email' => 'forged@example.com',    // must be ignored
            'phone' => '9876543210', 'country_code' => '+91',
        ])->assertOk();

        Http::assertSent(function ($request) {
            // password must be ABSENT entirely — the admin's min:6 rule runs
            // even on a null password and rejects the Google signup.
            return str_contains($request->url(), 'v1/user/')
                && $request['email'] === 'real@gmail.com'
                && $request['login_type'] === 'google'
                && ! array_key_exists('password', $request->data());
        });
    }

    public function test_a_verified_google_token_signs_an_existing_rider_in(): void
    {
        $this->fake([
            'https://admin.test/api/v1/profilebyphone/' => Http::response($this->adminProfile()),
        ]);

        $this->postJson('/auth/firebase', [
            'id_token' => $this->token(['email' => 'asha@example.com', 'name' => 'Asha Rider']),
        ])->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'v1/profilebyphone/')
                && $request['login_type'] === 'google'
                && $request['email'] === 'asha@example.com';
        });
    }
}
