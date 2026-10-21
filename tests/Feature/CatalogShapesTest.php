<?php

namespace Tests\Feature;

use App\Services\Cabme\CatalogApi;
use App\Support\ApiResult;
use App\Support\CabmeSettings;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin's catalogue endpoints return awkward shapes. These pin the
 * translations down against payloads captured from a running cabme-admin.
 */
class CatalogShapesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);

        $this->withSession(['cabme_rider' => [
            'id' => 42, 'token' => 'tok-123', 'profile' => ['id' => 42],
        ]])->get('/');
    }

    public function test_payment_methods_are_lifted_out_of_the_top_level_gateway_map(): void
    {
        // v1/payment-settings/ puts each gateway at the TOP level of the body,
        // not under `data` — captured verbatim from the running admin.
        Http::preventStrayRequests();
        Http::fake([
            'https://admin.test/api/v1/payment-settings/' => Http::response([
                'success' => 'success',
                'error' => null,
                'message' => 'ok',
                'Strip' => [['id' => '1', 'isEnabled' => 'true', 'id_payment_method' => '10', 'libelle' => 'Stripe']],
                'Cash' => [['id' => '2', 'isEnabled' => 'true', 'id_payment_method' => '5', 'libelle' => 'Cash']],
                'My Wallet' => [['id' => '4', 'isEnabled' => 'true', 'id_payment_method' => '9', 'libelle' => 'Wallet']],
                'PayFast' => [['id' => '3', 'isEnabled' => 'false', 'id_payment_method' => '7', 'libelle' => 'PayFast']],
                // Mercadopago sends no libelle; the bucket key is the fallback.
                'Mercadopago' => [['id' => '12', 'isEnabled' => 'true', 'id_payment_method' => '16']],
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);

        $methods = app(CatalogApi::class)->enabledPaymentMethods();

        $labels = array_column($methods, 'label', 'id');

        $this->assertSame('Stripe', $labels[10]);
        $this->assertSame('Cash', $labels[5]);
        $this->assertSame('Wallet', $labels[9]);
        $this->assertSame('Mercadopago', $labels[16], 'bucket key should stand in for a missing libelle');

        $this->assertArrayNotHasKey(7, $labels, 'disabled gateways must not be offered');
        $this->assertArrayNotHasKey(0, $labels, 'envelope keys must not become methods');
    }

    public function test_an_empty_payment_payload_yields_no_methods(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://admin.test/api/v1/payment-settings/' => Http::response([
                'success' => 'success', 'error' => null, 'message' => 'ok',
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);

        $this->assertSame([], app(CatalogApi::class)->enabledPaymentMethods());
    }

    /** "No data found" means empty, not broken. */
    public function test_an_empty_list_is_not_reported_as_an_error(): void
    {
        $empty = ApiResult::fromResponse(new Response(new PsrResponse(200, [],
            json_encode(['success' => 'Failed', 'code' => 200, 'message' => 'No data found', 'data' => null]),
        )));

        $this->assertTrue($empty->failed());
        $this->assertTrue($empty->isEmptyResult());

        $broken = ApiResult::fromResponse(new Response(new PsrResponse(200, [],
            json_encode(['success' => 'Failed', 'message' => 'The user id field is required.']),
        )));

        $this->assertTrue($broken->failed());
        $this->assertFalse($broken->isEmptyResult(), 'a real error must still surface');

        $ok = ApiResult::fromResponse(new Response(new PsrResponse(200, [],
            json_encode(['success' => 'success', 'data' => [1, 2]]),
        )));

        $this->assertFalse($ok->isEmptyResult());
    }

    public function test_the_raw_body_is_kept_for_endpoints_that_skip_the_data_key(): void
    {
        $result = ApiResult::fromResponse(new Response(new PsrResponse(200, [],
            json_encode(['success' => 'success', 'Cash' => [['id' => '2']]]),
        )));

        $this->assertNull($result->data);
        $this->assertSame([['id' => '2']], $result->raw['Cash']);
    }
}
