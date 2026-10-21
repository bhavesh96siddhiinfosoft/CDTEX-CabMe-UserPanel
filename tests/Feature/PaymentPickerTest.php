<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The wallet row carries the rider's own balance, because that is the one
 * method whose usability depends on something only they can see. With nothing
 * in it the row stays listed — hiding it would leave riders wondering where
 * the wallet went — but it cannot be chosen.
 */
class PaymentPickerTest extends TestCase
{
    private function signIn(string $balance): void
    {
        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);

        Http::preventStrayRequests();
        Http::fake([
            'https://admin.test/api/v1/settings/' => Http::response([
                'success' => 'success',
                'data' => ['title' => 'CabMe', 'currency' => '$', 'active_services' => ['ride']],
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);

        session()->put('cabme_rider', [
            'id' => 42, 'token' => 'tok-123',
            'profile' => ['id' => 42, 'email' => 'a@b.test', 'amount' => $balance],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function methods(): array
    {
        return [
            ['id' => 5, 'label' => 'Cash', 'logo' => null, 'kind' => 'offline', 'flow' => 'none', 'charged_in' => null],
            ['id' => 9, 'label' => 'Wallet', 'logo' => null, 'kind' => 'wallet', 'flow' => 'none', 'charged_in' => null],
        ];
    }

    private function render(): string
    {
        // TestView stringifies to the rendered html.
        return (string) $this->blade(
            '<x-payment-picker :methods="$methods" />',
            ['methods' => $this->methods()],
        );
    }

    public function test_the_wallet_row_shows_the_balance(): void
    {
        $this->signIn('1250.50');

        $html = $this->render();

        $this->assertStringContainsString('$1,250.50', $html);
        $this->assertStringContainsString('Balance', $html);
    }

    public function test_a_funded_wallet_can_be_chosen(): void
    {
        $this->signIn('1250.50');

        $html = $this->render();

        // The wallet radio is the one valued 9; it must not be disabled.
        preg_match('/<input[^>]*value="9"[^>]*>/', $html, $wallet);

        $this->assertNotEmpty($wallet, 'the wallet row should be listed');
        $this->assertStringNotContainsString('disabled', $wallet[0]);
    }

    public function test_an_empty_wallet_is_listed_but_cannot_be_chosen(): void
    {
        $this->signIn('0.00');

        $html = $this->render();

        preg_match('/<input[^>]*value="9"[^>]*>/', $html, $wallet);

        $this->assertNotEmpty($wallet, 'an empty wallet should still be listed');
        $this->assertStringContainsString('disabled', $wallet[0]);
        $this->assertStringContainsString('Top up to use it', $html);

        // Other methods stay selectable.
        preg_match('/<input[^>]*value="5"[^>]*>/', $html, $cash);
        $this->assertStringNotContainsString('disabled', $cash[0]);
    }
}
