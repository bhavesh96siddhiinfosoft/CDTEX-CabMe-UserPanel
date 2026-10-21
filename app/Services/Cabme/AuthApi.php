<?php

namespace App\Services\Cabme;

use App\Support\ApiResult;
use App\Support\Rider;

/** Rider account endpoints. Everything here uses account_type/user_cat "customer". */
class AuthApi
{
    public function __construct(
        private readonly CabmeClient $client,
        private readonly Rider $rider,
    ) {
    }

    public function login(string $email, string $password): ApiResult
    {
        return $this->client->guest('POST', 'v1/user-login/', [
            'email' => $email,
            'password' => $password,
            'user_cat' => 'customer',
        ]);
    }

    public function register(array $data): ApiResult
    {
        $payload = [
            'firstname' => $data['firstname'],
            'lastname' => $data['lastname'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'country_code' => $data['country_code'],
            'login_type' => $data['login_type'] ?? 'email',
            'account_type' => 'customer',
            'referral_code' => $data['referral_code'] ?? null,
            'tonotify' => $data['tonotify'] ?? 'yes',
        ];

        // Google/Apple signups carry no password, and the key must be ABSENT:
        // the admin's `required_unless:login_type,google,apple|min:6` still
        // runs min:6 on a present-but-null password and rejects the signup.
        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        return $this->client->guest('POST', 'v1/user/', $payload);
    }

    public function logout(): ApiResult
    {
        return $this->client->guest('POST', 'v1/logout/', [
            'user_id' => $this->rider->id(),
            'user_cat' => 'customer',
        ]);
    }

    /** Check whether a phone/email is already registered, before showing the form. */
    public function existingUser(array $data): ApiResult
    {
        return $this->client->guest('POST', 'v1/existing-user/', $data);
    }

    public function profileByPhone(string $phone, string $countryCode): ApiResult
    {
        return $this->client->guest('POST', 'v1/profilebyphone/', [
            'phone' => $phone,
            'country_code' => $countryCode,
            'user_cat' => 'customer',
            'login_type' => 'phoneNumber',
        ]);
    }

    /**
     * Social sign-in lookup: any non-phoneNumber login_type makes the admin
     * match by email instead. Returns the full row incl. a fresh accesstoken.
     */
    public function profileByEmail(string $email, string $loginType = 'google'): ApiResult
    {
        return $this->client->guest('POST', 'v1/profilebyphone/', [
            'email' => $email,
            'user_cat' => 'customer',
            'login_type' => $loginType,
        ]);
    }

    /**
     * Re-read the rider's row from the admin and update the session snapshot.
     *
     * The session copy is taken at login and never changes on its own, so
     * anything that displays server-owned state — the wallet balance above
     * all — has to refresh first or it will show a stale figure.
     *
     * There is no "get my profile" endpoint, so this goes through
     * profilebyphone, which returns the whole user row including `amount`.
     */
    public function refreshProfile(): bool
    {
        $phone = $this->rider->get('phone');
        $countryCode = $this->rider->get('country_code');
        $email = $this->rider->get('email');

        // Prefer phone, but fall back to email: the endpoint supports both, and
        // email is the one field a signed-in rider is guaranteed to have, since
        // logging in requires it. Relying on phone alone left the balance stuck
        // at whatever it was when the session started.
        if ($phone && $countryCode) {
            $result = $this->profileByPhone($phone, $countryCode);
        } elseif ($email) {
            $result = $this->client->guest('POST', 'v1/profilebyphone/', [
                'email' => $email,
                'user_cat' => 'customer',
                'login_type' => 'email',
            ]);
        } else {
            return false;
        }

        $profile = $result->data;
        if (is_array($profile) && isset($profile[0]) && is_array($profile[0])) {
            $profile = $profile[0];
        }

        if ($result->failed() || ! is_array($profile)) {
            return false;
        }

        // Never let a refresh clobber the access token we are authenticating with.
        unset($profile['accesstoken']);

        $this->rider->updateProfile($profile);

        return true;
    }

    /**
     * The rider's audience, as ResertPasswordController names it. It accepts
     * only `user_app` or `driver` — not the `customer` used elsewhere in the
     * API — and demands it on both steps of the reset.
     */
    private const RESET_AUDIENCE = 'user_app';

    public function sendResetOtp(string $email): ApiResult
    {
        return $this->client->guest('POST', 'v1/reset-password-otp/', [
            'email' => $email,
            'user_cat' => self::RESET_AUDIENCE,
        ]);
    }

    /**
     * @param  array{email:string, otp:string, password:string}  $data
     */
    public function resetPassword(array $data): ApiResult
    {
        return $this->client->guest('POST', 'v1/reset-password/', [
            'email' => $data['email'],
            'otp' => $data['otp'],
            'user_cat' => self::RESET_AUDIENCE,
            // The admin reads these two names, and requires them to match.
            'new_password' => $data['password'],
            'confirm_password' => $data['password'],
        ]);
    }

    /**
     * Change password for a signed-in rider.
     *
     * UsermdpController checks the current password with Hash::check before
     * writing, so it has to be sent too; its field names are the French ones
     * (anc_mdp / new_mdp) and its audience is user_app, not `customer`.
     */
    public function changePassword(string $currentPassword, string $newPassword): ApiResult
    {
        return $this->client->auth('POST', 'v1/update-user-mdp/', [
            'id_user' => $this->rider->id(),
            'user_cat' => self::RESET_AUDIENCE,
            'anc_mdp' => $currentPassword,
            'new_mdp' => $newPassword,
        ]);
    }

    /**
     * UserProfileUpdateController reads: id_user, user_type, prenom, nom,
     * email, phone, country_code, and the photo as `image` — French column
     * names and id_user, not the firstname/user_id used elsewhere.
     */
    public function updateProfile(array $fields, array $files = []): ApiResult
    {
        $payload = [
            'id_user' => $this->rider->id(),
            'user_type' => 'customer',
            'prenom' => $fields['firstname'] ?? $this->rider->get('prenom'),
            'nom' => $fields['lastname'] ?? $this->rider->get('nom'),
            'email' => $fields['email'] ?? $this->rider->get('email'),
            'phone' => $fields['phone'] ?? $this->rider->get('phone'),
            'country_code' => $fields['country_code'] ?? $this->rider->get('country_code'),
        ];

        if (isset($files['photo'])) {
            $files = ['image' => $files['photo']];
        }

        return $files
            ? $this->client->upload('v1/update-user-profile/', $payload, $files)
            : $this->client->auth('POST', 'v1/update-user-profile/', $payload);
    }

    public function deleteAccount(): ApiResult
    {
        return $this->client->auth('POST', 'v1/user-delete/', [
            'user_id' => $this->rider->id(),
            'user_cat' => 'customer',
        ]);
    }

    /**
     * The rider's referral code and what a referral pays.
     *
     * GetUserReferralCode reads `id_user`, not the `user_id` used by most
     * of the API — sending the wrong one returned "Not Found" for every
     * rider, so the page never showed a code to anyone.
     */
    public function referralCode(): ApiResult
    {
        return $this->client->auth('GET', 'v1/get-referral/', ['id_user' => $this->rider->id()]);
    }
}
