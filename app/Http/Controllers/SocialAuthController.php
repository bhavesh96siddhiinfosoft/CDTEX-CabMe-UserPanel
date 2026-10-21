<?php

namespace App\Http\Controllers;

use App\Services\Cabme\AuthApi;
use App\Services\FirebaseTokenVerifier;
use App\Support\Rider;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phone-OTP and Google sign-in, exactly as the mobile apps do it.
 *
 * Firebase Authentication runs in the browser (SMS OTP / Google popup); the
 * browser sends back only the Firebase ID token. The token is verified
 * server-side and its VERIFIED claims (phone_number / email) drive the admin
 * calls — the same v1/profilebyphone/ + v1/user/ endpoints the Flutter app
 * uses with login_type phoneNumber / google.
 */
class SocialAuthController extends Controller
{
    public function __construct(
        private readonly FirebaseTokenVerifier $verifier,
        private readonly AuthApi $api,
        private readonly Rider $rider,
    ) {
    }

    /** Step 1 — sign in with a verified Firebase identity, or ask to register. */
    public function login(Request $request)
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:4096'],
            // The browser's phone split; must reassemble the token's number.
            'phone' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', 'string', 'max:8'],
        ]);

        $claims = $this->verifier->verify($data['id_token']);

        if (! $claims) {
            return response()->json(['message' => __('Could not sign you in. Please try again.')], 422);
        }

        if (! empty($claims['phone_number'])) {
            $split = $this->phoneSplit($claims, $data);

            if (! $split) {
                return response()->json(['message' => __('Could not sign you in. Please try again.')], 422);
            }

            $result = $this->api->profileByPhone($split['phone'], $split['country_code']);

            if ($result->ok && ! empty($result->data['accesstoken'])) {
                return $this->establishSession($request, $result->data);
            }

            // New rider: name + email still needed before v1/user/ can run.
            return response()->json(['register' => true, 'mode' => 'phone']);
        }

        $email = (string) ($claims['email'] ?? '');

        if ($email === '') {
            return response()->json(['message' => __('Could not sign you in. Please try again.')], 422);
        }

        $result = $this->api->profileByEmail($email);

        if ($result->ok && ! empty($result->data['accesstoken'])) {
            return $this->establishSession($request, $result->data);
        }

        // New rider: the admin requires a phone number on every account.
        [$first, $last] = $this->splitName((string) ($claims['name'] ?? ''));

        return response()->json([
            'register' => true,
            'mode' => 'google',
            'prefill' => ['firstname' => $first, 'lastname' => $last, 'email' => $email],
        ]);
    }

    /** Step 2 (new riders only) — create the account against the same token. */
    public function register(Request $request)
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:4096'],
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', 'string', 'max:8'],
            'referral_code' => ['nullable', 'string', 'max:32'],
            // The signup form collects one (app parity) so the account also
            // works with email + password sign-in. Nullable for old clients.
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
            'password_confirmation' => ['nullable', 'string', 'max:100'],
        ]);

        if (($data['password'] ?? '') !== '' && $data['password'] !== ($data['password_confirmation'] ?? '')) {
            return response()->json(['message' => __('Passwords do not match.')], 422);
        }

        $claims = $this->verifier->verify($data['id_token']);

        if (! $claims) {
            return response()->json(['message' => __('Could not create your account. Please try again.')], 422);
        }

        if (! empty($claims['phone_number'])) {
            // Phone came from the OTP; the email is the form's to fill.
            $split = $this->phoneSplit($claims, $data);

            if (! $split) {
                return response()->json(['message' => __('Could not create your account. Please try again.')], 422);
            }

            if (empty($data['email'])) {
                return response()->json(['message' => __('Enter your email address.')], 422);
            }

            $payload = [
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'email' => $data['email'],
                'phone' => $split['phone'],
                'country_code' => $split['country_code'],
                'login_type' => 'phoneNumber',
                // The signup form asks for one; a client that sent none gets
                // a random placeholder (the admin requires a password).
                'password' => ($data['password'] ?? '') !== '' ? $data['password'] : Str::random(20),
                'referral_code' => $data['referral_code'] ?? null,
            ];
        } else {
            // Google: the email comes from the VERIFIED token, never the form.
            $email = (string) ($claims['email'] ?? '');

            if ($email === '') {
                return response()->json(['message' => __('Could not create your account. Please try again.')], 422);
            }

            if (empty($data['phone']) || empty($data['country_code'])) {
                return response()->json(['message' => __('Enter your mobile number first.')], 422);
            }

            $payload = [
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'email' => $email,
                'phone' => preg_replace('/\D/', '', $data['phone']),
                'country_code' => $data['country_code'],
                'login_type' => 'google',
                'referral_code' => $data['referral_code'] ?? null,
            ];

            // Only when chosen — the admin's google path must not see a null
            // password key (min:6 runs on present-null and rejects it).
            if (($data['password'] ?? '') !== '') {
                $payload['password'] = $data['password'];
            }
        }

        $result = $this->api->register($payload);

        if ($result->failed() || empty($result->data['accesstoken'])) {
            return response()->json([
                'message' => $result->messageOr(__('Could not create your account. Please try again.')),
            ], 422);
        }

        return $this->establishSession($request, $result->data);
    }

    private function establishSession(Request $request, array $profile)
    {
        $this->rider->login($profile);

        $intended = $request->session()->pull('intended');

        return response()->json(['ok' => true, 'redirect' => $intended ?: route('app.home')]);
    }

    /**
     * The admin stores '+91' + national number separately; the token carries
     * one E.164 string. The browser proposes the split, and it only counts
     * when it reassembles the verified number exactly.
     *
     * @return array{phone:string,country_code:string}|null
     */
    private function phoneSplit(array $claims, array $data): ?array
    {
        $verified = (string) $claims['phone_number'];
        $code = trim((string) ($data['country_code'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));

        if ($code !== '' && $phone !== '' && $code.$phone === $verified) {
            return ['phone' => $phone, 'country_code' => $code];
        }

        return null;
    }

    /** "Jane van Doe" → ["Jane", "van Doe"]; single names get a dash. */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '-')];
    }
}
