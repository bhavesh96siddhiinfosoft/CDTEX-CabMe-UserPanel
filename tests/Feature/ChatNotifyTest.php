<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The chat thread itself lives in Firestore and the browser writes it, but a
 * driver with the app closed only learns about a message from a push — and
 * that needs their FCM token, which the rider must never be handed. So the
 * panel looks it up server-side and sends the notification itself.
 */
class ChatNotifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);

        session()->put('cabme_rider', [
            'id' => 42, 'token' => 'tok-123',
            'profile' => ['id' => 42, 'prenom' => 'Thakarshi', 'nom' => 'Makwana', 'email' => 'a@b.test'],
        ]);

        Http::preventStrayRequests();
    }

    public function test_it_looks_the_driver_token_up_and_never_returns_it(): void
    {
        Http::fake([
            'https://admin.test/api/v1/fcm-token/' => Http::response([
                'success' => 'success', 'data' => ['fcm_id' => 'driver-device-token'],
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);

        $response = $this->postJson('/app/chat/notify', [
            'driver_id' => 9,
            'order_id' => 77,
            'message' => 'I am at the gate',
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertStringNotContainsString('driver-device-token', $response->content());

        // Asked the admin for the DRIVER's token, not the rider's.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'fcm-token')
                && (int) $request['id_user'] === 9
                && $request['cat_user'] === 'driver';
        });
    }

    public function test_a_driver_without_a_token_is_not_an_error(): void
    {
        Http::fake([
            'https://admin.test/api/v1/fcm-token/' => Http::response([
                'success' => 'success', 'data' => ['fcm_id' => ''],
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);

        // The message is already in Firestore; the driver simply sees it when
        // they next open the chat, so this must not read as a failure.
        $this->postJson('/app/chat/notify', [
            'driver_id' => 9, 'order_id' => 77, 'message' => 'Hello',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('notified', false);
    }

    public function test_it_is_closed_to_visitors_who_are_not_signed_in(): void
    {
        session()->forget('cabme_rider');

        Http::fake(['*' => Http::response(['success' => 'success', 'data' => []])]);

        $this->postJson('/app/chat/notify', [
            'driver_id' => 9, 'order_id' => 77, 'message' => 'Hello',
            // A JSON call gets a flat 401 rather than a redirect to the
            // login page, which is what the browser can act on.
        ])->assertStatus(401);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fcm-token'));
    }

    public function test_it_rejects_a_message_that_is_not_a_message(): void
    {
        Http::fake(['*' => Http::response(['success' => 'success', 'data' => []])]);

        $this->postJson('/app/chat/notify', ['driver_id' => 9, 'order_id' => 77])
            ->assertStatus(422);

        $this->postJson('/app/chat/notify', [
            'driver_id' => 9, 'order_id' => 77, 'message' => str_repeat('x', 1001),
        ])->assertStatus(422);
    }
}
