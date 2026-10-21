<?php

namespace App\Support;

use App\Services\Cabme\CabmeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Firebase service-account credentials, sourced from the ADMIN panel.
 *
 * The admin's Settings → Notifications screen uploads the service-account
 * JSON (settings.serviceJsonData) with the sender id beside it. This mirrors
 * that JSON to storage/app/firebase/credentials.json — the same layout the
 * admin itself uses — refreshing when the admin copy changes, so re-uploading
 * a key there needs no deploy here.
 */
class FirebaseCredentials
{
    public const PATH = 'firebase/credentials.json';

    private const CACHE = 'cabme.firebase.credentials';

    public function __construct(private readonly CabmeClient $client)
    {
    }

    /**
     * @return array{sender_id:string,project_id:string,client_email:string,private_key:string}|null
     */
    public function get(): ?array
    {
        $cached = Cache::get(self::CACHE);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $result = $this->client->guest('GET', 'v1/firebase-credentials/');
            $json = (string) ($result->data['service_json_data'] ?? '');
            $senderId = (string) ($result->data['sender_id'] ?? '');
        } catch (\Throwable) {
            // Push must never take a page down with it.
            $json = '';
            $senderId = '';
        }
        $account = json_decode($json, true);

        if (! is_array($account) || empty($account['private_key']) || empty($account['project_id'])) {
            // Fall back to a previously mirrored file (admin briefly down).
            $account = $this->fromDisk();

            if (! $account) {
                return null;
            }

            $json = json_encode($account);
        }

        $this->mirror($json);

        $credentials = [
            'sender_id' => $senderId !== '' ? $senderId : (string) ($account['sender_id'] ?? ''),
            'project_id' => (string) $account['project_id'],
            'client_email' => (string) $account['client_email'],
            'private_key' => (string) $account['private_key'],
        ];

        Cache::put(self::CACHE, $credentials, 300);

        return $credentials;
    }

    /** Write the JSON to storage/app/firebase/credentials.json when changed. */
    private function mirror(string $json): void
    {
        $path = storage_path('app/'.self::PATH);

        try {
            if (! File::exists($path) || md5((string) File::get($path)) !== md5($json)) {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, $json);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not mirror Firebase credentials to disk', ['error' => $e->getMessage()]);
        }
    }

    private function fromDisk(): ?array
    {
        $path = storage_path('app/'.self::PATH);

        if (! File::exists($path)) {
            return null;
        }

        $account = json_decode((string) File::get($path), true);

        return is_array($account) && ! empty($account['private_key']) ? $account : null;
    }
}
