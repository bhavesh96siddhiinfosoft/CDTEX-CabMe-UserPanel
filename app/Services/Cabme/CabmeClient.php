<?php

namespace App\Services\Cabme;

use App\Support\ApiResult;
use App\Support\Rider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The single door to cabme-admin.
 *
 * Guest endpoints need the `apikey` header. Authenticated endpoints need
 * `apikey` + the rider's `accesstoken`, which the admin looks up in its
 * users_access table. Nothing else in this panel builds an outbound request.
 */
class CabmeClient
{
    public function __construct(private readonly Rider $rider)
    {
    }

    /** Call a guest endpoint (apikey only). */
    public function guest(string $method, string $path, array $payload = []): ApiResult
    {
        return $this->send($method, $path, $payload, withToken: false);
    }

    /** Call an endpoint that requires a signed-in rider. */
    public function auth(string $method, string $path, array $payload = []): ApiResult
    {
        if (! $this->rider->token()) {
            return ApiResult::failure(__('Your session has expired. Please sign in again.'), 401);
        }

        $result = $this->send($method, $path, $payload, withToken: true);

        // The admin deletes rows from users_access on logout and on account
        // deletion, so a 401 here means this session's token is dead. Clear it
        // so the middleware bounces the rider to /login instead of looping.
        if ($result->unauthorized()) {
            $this->rider->forget();
        }

        return $result;
    }

    private function send(string $method, string $path, array $payload, bool $withToken): ApiResult
    {
        $method = strtoupper($method);
        $url = config('cabme.base_url').'/api/'.ltrim($path, '/');

        try {
            $response = $this->request($withToken)->send($method, $url, [
                $method === 'GET' ? 'query' : 'json' => $payload,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('CabMe API unreachable', ['path' => $path, 'error' => $e->getMessage()]);

            return ApiResult::failure(__('Could not reach the CabMe service. Please try again.'), 503);
        }

        $result = ApiResult::fromResponse($response);

        if (config('cabme.debug')) {
            Log::debug('CabMe API', [
                'path' => $path,
                'payload' => $this->redact($payload),
                'ok' => $result->ok,
                'status' => $result->status,
                'message' => $result->message,
            ]);
        }

        return $result;
    }

    private function request(bool $withToken): PendingRequest
    {
        $headers = ['apikey' => config('cabme.api_key'), 'Accept' => 'application/json'];

        if ($withToken) {
            $headers['accesstoken'] = $this->rider->token();
        }

        return Http::withHeaders($headers)
            ->timeout(config('cabme.timeout'))
            // Only retry idempotent transport failures; a retried ride-book
            // would create a duplicate booking.
            ->retry(config('cabme.retries'), 200, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /** Multipart upload (profile photo, parcel images, complaint attachments). */
    public function upload(string $path, array $fields, array $files): ApiResult
    {
        if (! $this->rider->token()) {
            return ApiResult::failure(__('Your session has expired. Please sign in again.'), 401);
        }

        $request = $this->request(withToken: true);

        foreach ($files as $name => $file) {
            if ($file) {
                $request = $request->attach($name, file_get_contents($file->getRealPath()), $file->getClientOriginalName());
            }
        }

        try {
            $response = $request->post(config('cabme.base_url').'/api/'.ltrim($path, '/'), $fields);
        } catch (ConnectionException $e) {
            return ApiResult::failure(__('Could not reach the CabMe service. Please try again.'), 503);
        }

        return ApiResult::fromResponse($response);
    }

    private function redact(array $payload): array
    {
        foreach (['password', 'new_password', 'old_password', 'mdp'] as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = '***';
            }
        }

        return $payload;
    }
}
