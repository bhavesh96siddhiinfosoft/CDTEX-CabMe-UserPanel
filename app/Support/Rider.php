<?php

namespace App\Support;

use Illuminate\Contracts\Session\Session;

/**
 * The signed-in rider, held entirely in the session.
 *
 * This panel has no users table — cabme-admin owns rider accounts. Logging in
 * exchanges credentials for an `accesstoken` from the admin's users_access
 * table; that token plus the profile snapshot is all we keep.
 */
class Rider
{
    private const KEY = 'cabme_rider';

    public function __construct(private readonly Session $session)
    {
    }

    /** Store the payload returned by v1/user-login/ or v1/user/. */
    public function login(array $profile): void
    {
        $this->session->put(self::KEY, [
            'id' => $profile['id'] ?? null,
            'token' => $profile['accesstoken'] ?? null,
            'profile' => $profile,
        ]);

        // New identity, new session id — the login response is a privilege
        // change, so don't let a pre-login session id carry over.
        $this->session->regenerate();
    }

    public function check(): bool
    {
        return (bool) ($this->id() && $this->token());
    }

    public function id(): ?int
    {
        $id = $this->session->get(self::KEY.'.id');

        return $id === null ? null : (int) $id;
    }

    public function token(): ?string
    {
        return $this->session->get(self::KEY.'.token');
    }

    public function profile(): array
    {
        return $this->session->get(self::KEY.'.profile', []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->profile(), $key, $default);
    }

    public function name(): string
    {
        $name = trim(($this->get('prenom') ?? '').' '.($this->get('nom') ?? ''));

        return $name !== '' ? $name : 'Rider';
    }

    public function photo(): ?string
    {
        return $this->get('photo_path');
    }

    /**
     * Wallet balance. The admin keeps it on user_app.`amount` — not
     * `wallet_amount` or `balance`, which do not exist on that table.
     *
     * This reads the session snapshot, which is only as fresh as the last
     * refresh, so anything showing a balance should call
     * AuthApi::refreshProfile() first.
     */
    public function balance(): float
    {
        return (float) $this->get('amount', 0);
    }

    /** Refresh the cached profile without touching the token. */
    public function updateProfile(array $profile): void
    {
        $current = $this->session->get(self::KEY, []);
        $current['profile'] = array_merge($current['profile'] ?? [], $profile);
        $this->session->put(self::KEY, $current);
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }

    public function logout(): void
    {
        $this->forget();
        $this->session->invalidate();
        $this->session->regenerateToken();
    }
}
