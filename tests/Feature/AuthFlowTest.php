<?php

namespace Tests\Feature;

use App\Support\Rider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the whole signed-in path: controller → CabmeClient → session identity.
 * The admin API is faked, so these assert our contract with it, not its uptime.
 */
class AuthFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        // Settings is fetched by the shared view composer on every render.
        Http::preventStrayRequests();
        Http::fake([
            'https://admin.test/api/v1/settings/' => Http::response([
                'success' => 'success',
                'data' => ['active_services' => ['ride'], 'currency' => '$', 'decimal_digit' => 2],
            ]),
        ]);
    }

    public function test_a_rider_can_sign_in_and_the_token_is_stored(): void
    {
        Http::fake([
            'https://admin.test/api/v1/user-login/' => Http::response([
                'success' => 'Success',
                'message' => 'Login Successfully.',
                'data' => ['id' => 42, 'prenom' => 'Asha', 'nom' => 'Rao', 'accesstoken' => 'tok-123'],
            ]),
            'https://admin.test/api/v1/settings/' => Http::response([
                'success' => 'success',
                'data' => ['active_services' => ['ride']],
            ]),
        ]);

        $response = $this->post('/login', [
            'email' => 'asha@example.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('app.home'));

        $rider = app(Rider::class);
        $this->assertTrue($rider->check());
        $this->assertSame(42, $rider->id());
        $this->assertSame('tok-123', $rider->token());
        $this->assertSame('Asha Rao', $rider->name());

        // The admin identifies the rider type on the way in. Other calls go
        // out during the request too (settings, for one), so match on the
        // login call rather than assuming every request carries the field.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'v1/user-login/')
            && ($request->data()['user_cat'] ?? null) === 'customer'
            && $request->hasHeader('apikey', 'base64:test-key'));
    }

    public function test_a_rejected_login_reports_the_admin_message(): void
    {
        Http::fake([
            'https://admin.test/api/v1/user-login/' => Http::response([
                'success' => 'Failed',
                'error' => 'Incorrect Password.',
            ]),
            'https://admin.test/api/v1/settings/' => Http::response(['success' => 'success', 'data' => []]),
        ]);

        $this->from('/login')
            ->post('/login', ['email' => 'asha@example.com', 'password' => 'wrongpass'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Incorrect Password.']);

        $this->assertFalse(app(Rider::class)->check());
    }

    public function test_the_rider_area_is_closed_to_guests(): void
    {
        $this->get('/app')->assertRedirect(route('login'));
        $this->get('/app/wallet')->assertRedirect(route('login'));
        $this->get('/app/ride')->assertRedirect(route('login'));
    }

    public function test_authenticated_requests_carry_the_access_token(): void
    {
        Http::fake([
            'https://admin.test/api/v1/get-wallet-history/' => Http::response([
                'success' => 'success',
                'data' => [['amount' => 100, 'is_credited' => 1, 'note' => 'Top-up']],
            ]),
            'https://admin.test/api/v1/payment-settings/' => Http::response(['success' => 'success', 'data' => []]),
            'https://admin.test/api/v1/settings/' => Http::response(['success' => 'success', 'data' => []]),
        ]);

        $this->withSession(['cabme_rider' => [
            'id' => 42,
            'token' => 'tok-123',
            'profile' => ['id' => 42, 'prenom' => 'Asha', 'nom' => 'Rao'],
        ]])->get('/app/wallet')->assertOk()->assertSee('Top-up');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'get-wallet-history')
            && $request->hasHeader('accesstoken', 'tok-123'));
    }

    public function test_a_dead_token_is_cleared_so_the_rider_is_not_looped(): void
    {
        // The admin deletes users_access rows on logout; a stale session token
        // must be dropped rather than retried forever.
        Http::fake([
            'https://admin.test/api/v1/get-wallet-history/' => Http::response(
                ['data' => ['status' => 401, 'error' => 'Unauthorized']], 401,
            ),
            'https://admin.test/api/v1/payment-settings/' => Http::response(
                ['data' => ['status' => 401, 'error' => 'Unauthorized']], 401,
            ),
            'https://admin.test/api/v1/settings/' => Http::response(['success' => 'success', 'data' => []]),
        ]);

        $this->withSession(['cabme_rider' => [
            'id' => 42,
            'token' => 'dead-token',
            'profile' => ['id' => 42],
        ]])->get('/app/wallet')->assertOk();

        $this->assertFalse(app(Rider::class)->check());
    }

    public function test_registration_errors_render_at_the_top_of_the_form(): void
    {
        Http::fake([
            "https://admin.test/api/v1/user/" => Http::response([
                "success" => "Failed", "message" => "Email already exists.",
            ]),
            "*" => Http::response(["success" => "success", "data" => []]),
        ]);

        $response = $this->from("/register")->post("/register", [
            "firstname" => "Asha", "lastname" => "Rao",
            "email" => "asha@example.com", "phone" => "9876543210", "country_code" => "+91",
            "password" => "secret123", "password_confirmation" => "secret123",
        ])->assertRedirect("/register");

        $html = $this->get("/register")->getContent();

        // Errors surface as TOASTS: the flash partial queues them for the
        // toasts store — no in-page banner anywhere.
        $this->assertStringContainsString("__flashQueue", $html);
        $this->assertStringContainsString("Email already exists.", $html);
        $this->assertStringNotContainsString("border-s-rose-500", $html);
    }
}