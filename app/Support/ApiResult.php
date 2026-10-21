<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;

/**
 * A single, predictable shape for every cabme-admin API response.
 *
 * The admin is inconsistent about how it reports outcomes — it returns at
 * least four different envelopes:
 *
 *   1. ['success' => 'Success'|'success', 'message' => .., 'data' => ..]
 *   2. ['success' => 'Failed'|'failed',  'error'|'message' => .., 'data' => null]
 *   3. ['status' => 1, 'message' => .., 'errors' => [], 'data' => ..]   (BaseApiController)
 *   4. ['response_time' => .., 'data' => ['status' => 401, 'error' => ..]]  (auth failure)
 *
 * Several endpoints also report logical failures with HTTP 200, so the status
 * code alone can never be trusted. Everything funnels through here instead.
 */
class ApiResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly mixed $data = null,
        public readonly string $message = '',
        public readonly int $status = 200,
        /**
         * The whole decoded body. A few endpoints (notably payment-settings)
         * put their payload at the top level instead of under `data`, so those
         * callers read this rather than $data.
         */
        public readonly array $raw = [],
    ) {
    }

    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $body = $response->json();

        // Non-JSON body (HTML error page, gateway timeout, empty response).
        if (! is_array($body)) {
            return new self(
                ok: false,
                message: $status >= 500
                    ? 'The CabMe service is unavailable. Please try again.'
                    : 'Unexpected response from the CabMe service.',
                status: $status,
            );
        }

        // Shape 4 — ApiKeyAuth / ApiSecureKeyAuth rejection.
        if ($status === 401 || Arr::get($body, 'data.status') === 401) {
            return new self(
                ok: false,
                message: Arr::get($body, 'data.error')
                    ?? Arr::get($body, 'data.message')
                    ?? 'Unauthorized',
                status: 401,
            );
        }

        // Shape 3 — BaseApiController::successResponse.
        if (array_key_exists('status', $body) && ! array_key_exists('success', $body)) {
            return new self(
                ok: (int) $body['status'] === 1,
                data: $body['data'] ?? null,
                message: (string) ($body['message'] ?? ''),
                status: $status,
            );
        }

        // Shapes 1 and 2 — the common case. 'success' is a string whose casing
        // varies per controller, so compare case-insensitively.
        $flag = strtolower((string) ($body['success'] ?? ''));
        $ok = in_array($flag, ['success', 'true', '1'], true);

        $message = $body['message'] ?? $body['error'] ?? '';
        if (is_array($message)) {
            $message = (string) (Arr::first(Arr::flatten($message)) ?? '');
        }

        return new self(
            ok: $ok,
            data: $body['data'] ?? null,
            message: (string) $message,
            status: (int) ($body['code'] ?? $status),
            raw: $body,
        );
    }

    public static function failure(string $message, int $status = 500): self
    {
        return new self(ok: false, message: $message, status: $status);
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }

    /** True when the admin rejected our api key or the rider's access token. */
    public function unauthorized(): bool
    {
        return $this->status === 401;
    }

    /** Read a dot-path out of the payload. */
    public function get(string $key, mixed $default = null): mixed
    {
        return is_array($this->data) ? Arr::get($this->data, $key, $default) : $default;
    }

    /** The payload as a list, whatever the admin wrapped it in. */
    public function list(): array
    {
        return is_array($this->data) ? $this->data : [];
    }

    /** A message safe to show a rider, with a fallback for empty admin errors. */
    public function messageOr(string $fallback): string
    {
        return trim($this->message) !== '' ? $this->message : $fallback;
    }

    /**
     * True when the admin is saying "nothing here", not "something broke".
     *
     * Several list endpoints report an empty result as a failure — e.g.
     * get-booking-list returns {"success":"Failed","message":"No data found"}
     * for a rider with no trips. Rendering that as an error banner is wrong;
     * it is an empty state.
     */
    public function isEmptyResult(): bool
    {
        if ($this->ok) {
            return false;
        }

        return (bool) preg_match('/no\s+(data|result|record)/i', $this->message);
    }
}
