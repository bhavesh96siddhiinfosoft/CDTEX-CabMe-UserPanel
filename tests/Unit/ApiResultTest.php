<?php

namespace Tests\Unit;

use App\Support\ApiResult;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;

/**
 * cabme-admin reports outcomes in several different envelopes, and some
 * endpoints report failure with HTTP 200. These lock in the translation.
 */
class ApiResultTest extends TestCase
{
    private function response(array|string $body, int $status = 200): Response
    {
        return new Response(new PsrResponse(
            $status,
            ['Content-Type' => 'application/json'],
            is_string($body) ? $body : json_encode($body),
        ));
    }

    public function test_it_reads_a_capitalised_success_envelope(): void
    {
        // Shape 1 — UserLoginController.
        $result = ApiResult::fromResponse($this->response([
            'success' => 'Success',
            'message' => 'Login Successfully.',
            'data' => ['id' => 7, 'accesstoken' => 'abc'],
        ]));

        $this->assertTrue($result->ok);
        $this->assertSame('abc', $result->get('accesstoken'));
        $this->assertSame('Login Successfully.', $result->message);
    }

    public function test_it_reads_a_lowercase_success_envelope(): void
    {
        // Shape 1 again — SettingsController uses lowercase.
        $result = ApiResult::fromResponse($this->response([
            'success' => 'success',
            'error' => null,
            'message' => 'Settings successfully found',
            'data' => ['currency' => '$'],
        ]));

        $this->assertTrue($result->ok);
        $this->assertSame('$', $result->get('currency'));
    }

    public function test_it_treats_a_failed_envelope_as_failure_despite_http_200(): void
    {
        // Shape 2 — the important one: logical failure carried on a 200.
        $result = ApiResult::fromResponse($this->response([
            'success' => 'Failed',
            'error' => 'Incorrect Password.',
        ]));

        $this->assertFalse($result->ok);
        $this->assertTrue($result->failed());
        $this->assertSame('Incorrect Password.', $result->message);
    }

    public function test_it_prefers_message_over_error_when_both_present(): void
    {
        $result = ApiResult::fromResponse($this->response([
            'success' => 'Failed',
            'code' => 404,
            'message' => 'The user id field is required.',
            'data' => null,
        ]));

        $this->assertFalse($result->ok);
        $this->assertSame('The user id field is required.', $result->message);
        $this->assertSame(404, $result->status);
    }

    public function test_it_reads_the_base_api_controller_envelope(): void
    {
        // Shape 3 — BaseApiController::successResponse.
        $result = ApiResult::fromResponse($this->response([
            'status' => 1,
            'message' => 'ok',
            'errors' => [],
            'data' => ['id' => 1],
        ]));

        $this->assertTrue($result->ok);
        $this->assertSame(1, $result->get('id'));
    }

    public function test_it_detects_the_real_admin_unauthorized_envelope(): void
    {
        // Shape 4 — captured verbatim from a running cabme-admin.
        $result = ApiResult::fromResponse($this->response(
            '{"response_time":0.18,"data":{"status":401,"message":"Unauthorized","error":"Unauthorized"}}',
            401,
        ));

        $this->assertFalse($result->ok);
        $this->assertTrue($result->unauthorized());
        $this->assertSame('Unauthorized', $result->message);
    }

    public function test_it_handles_a_non_json_body(): void
    {
        // The admin renders an HTML stack trace when the database is missing.
        $result = ApiResult::fromResponse($this->response('<!DOCTYPE html><html>...', 500));

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('unavailable', $result->message);
    }

    public function test_list_returns_an_array_whatever_the_payload(): void
    {
        $this->assertSame([], ApiResult::fromResponse($this->response([
            'success' => 'Failed', 'data' => null,
        ]))->list());

        $this->assertCount(2, ApiResult::fromResponse($this->response([
            'success' => 'success', 'data' => [['id' => 1], ['id' => 2]],
        ]))->list());
    }

    public function test_message_or_falls_back_when_the_admin_sends_nothing(): void
    {
        $result = ApiResult::fromResponse($this->response(['success' => 'Failed']));

        $this->assertSame('Could not book.', $result->messageOr('Could not book.'));
    }

    public function test_it_flattens_a_validation_error_bag(): void
    {
        // Some endpoints hand back the whole MessageBag rather than a string.
        $result = ApiResult::fromResponse($this->response([
            'success' => 'Failed',
            'message' => ['email' => ['The email field is required.']],
        ]));

        $this->assertSame('The email field is required.', $result->message);
    }
}
