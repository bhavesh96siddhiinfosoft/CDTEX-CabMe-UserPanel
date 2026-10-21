<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The hosted-page / async gateways follow the same rules as the rest: the
 * server decides the amount, the browser's return proves nothing, and every
 * verify re-reads the charge from the gateway (or, for PayFast, from its
 * validated ITN) before anything is credited. "Not settled yet" keeps the
 * pending record (HTTP 202) instead of burning it.
 */
class RedirectGatewaysTest extends TestCase
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
            'profile' => ['id' => 42, 'email' => 'a@b.test', 'phone' => '9876543210'],
        ]);
    }

    private function adminSettings(): array
    {
        return [
            'success' => 'success', 'error' => null, 'message' => 'ok',
            'Xendit' => [['id' => '1', 'isEnabled' => 'true', 'isSandboxEnabled' => 'true',
                'id_payment_method' => '20', 'libelle' => 'Xendit', 'key' => 'xnd_development_x']],
            'Cashfree' => [['id' => '2', 'isEnabled' => 'true', 'isSandboxEnabled' => 'true',
                'id_payment_method' => '21', 'libelle' => 'Cashfree',
                'clientpublishableKey' => 'TEST-client-id', 'secret_key' => 'cfsk_ma_test_x']],
            'PhonePe' => [['id' => '3', 'isEnabled' => 'true', 'isSandboxEnabled' => 'true',
                'id_payment_method' => '26', 'libelle' => 'PhonePe',
                'clientpublishableKey' => 'phonepe-client', 'secret_key' => 'phonepe-secret-key']],
            'Mercadopago' => [['id' => '4', 'isEnabled' => 'true', 'isSandboxEnabled' => 'true',
                'id_payment_method' => '16', 'accesstoken' => 'TEST-mp-access-token-long-enough']],
            'Paytm' => [['id' => '5', 'isEnabled' => 'true', 'isSandboxEnabled' => 'true',
                'id_payment_method' => '14', 'libelle' => 'Paytm',
                'merchant_Id' => 'TESTMID12345', 'merchant_key' => 'abcdefgh12345678']],
            'PayFast' => [['id' => '6', 'isEnabled' => 'true', 'isSandboxEnabled' => 'true',
                'id_payment_method' => '7', 'libelle' => 'PayFast',
                'merchant_Id' => '10000100', 'merchant_key' => '46f0cd694581a']],
            'Instamojo' => [['id' => '7', 'isEnabled' => 'true', 'isSandboxEnabled' => 'true',
                'id_payment_method' => '23', 'libelle' => 'Instamojo',
                'clientpublishableKey' => 'im-client-id', 'secret_key' => 'im-client-secret']],
            'PayMongo' => [['id' => '8', 'isEnabled' => 'true', 'isSandboxEnabled' => 'true',
                'id_payment_method' => '25', 'libelle' => 'PayMongo', 'secret_key' => 'sk_test_paymongo']],
            'Foloosi' => [['id' => '9', 'isEnabled' => 'true', 'isSandboxEnabled' => 'true',
                'id_payment_method' => '22', 'libelle' => 'Foloosi', 'merchant_key' => 'foloosi-merchant']],
        ];
    }

    private function fake(array $gatewayResponses = []): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge([
            'https://admin.test/api/v1/payment-settings/' => Http::response($this->adminSettings()),
            'https://admin.test/api/v1/settings/' => Http::response([
                'success' => 'success', 'data' => ['currency_code' => 'USD'],
            ]),
        ], $gatewayResponses, [
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]));
    }

    private function pending(string $gateway, array $extra): array
    {
        return ['booking_payment_pending' => array_merge([
            'gateway' => $gateway, 'amount' => 250.0, 'method_id' => 20, 'context' => 'parcel',
        ], $extra)];
    }

    public function test_xendit_order_returns_a_redirect_flow(): void
    {
        $this->fake([
            'https://api.xendit.co/v2/invoices' => Http::response([
                'id' => 'inv_1', 'invoice_url' => 'https://checkout.xendit.co/web/inv_1', 'status' => 'PENDING',
            ]),
        ]);

        $this->postJson('/app/pay/order', ['amount' => 250, 'payment_method_id' => 20, 'context' => 'parcel'])
            ->assertOk()
            ->assertJson(['gateway' => 'xendit', 'flow' => 'redirect']);

        // IDR rule: display × 1000.
        $this->assertSame(250000.0, session('booking_payment_pending.expected'));
        $this->assertSame('inv_1', session('booking_payment_pending.invoice_id'));
    }

    public function test_a_pending_xendit_invoice_keeps_the_session_record(): void
    {
        $this->fake([
            'https://api.xendit.co/v2/invoices/inv_1' => Http::response([
                'id' => 'inv_1', 'status' => 'PENDING', 'amount' => 250000, 'external_id' => 'parcel_42_ref',
            ]),
        ]);

        $this->withSession($this->pending('xendit', ['invoice_id' => 'inv_1', 'reference' => 'parcel_42_ref', 'expected' => 250000.0]))
            ->postJson('/app/pay/verify', [])
            ->assertStatus(202);

        $this->assertNotNull(session('booking_payment_pending'), 'pending must survive a 202');
    }

    public function test_a_paid_xendit_invoice_for_someone_elses_reference_is_rejected(): void
    {
        $this->fake([
            'https://api.xendit.co/v2/invoices/inv_1' => Http::response([
                'id' => 'inv_1', 'status' => 'PAID', 'amount' => 250000, 'external_id' => 'their_ref',
            ]),
        ]);

        $this->withSession($this->pending('xendit', ['invoice_id' => 'inv_1', 'reference' => 'parcel_42_ref', 'expected' => 250000.0]))
            ->postJson('/app/pay/verify', [])
            ->assertStatus(422);
    }

    public function test_a_settled_xendit_invoice_verifies(): void
    {
        $this->fake([
            'https://api.xendit.co/v2/invoices/inv_1' => Http::response([
                'id' => 'inv_1', 'status' => 'SETTLED', 'amount' => 250000, 'external_id' => 'parcel_42_ref',
            ]),
        ]);

        $this->withSession($this->pending('xendit', ['invoice_id' => 'inv_1', 'reference' => 'parcel_42_ref', 'expected' => 250000.0]))
            ->postJson('/app/pay/verify', [])
            ->assertOk();

        $this->assertSame('inv_1', session('booking_payment_verified.transaction_id'));
    }

    public function test_cashfree_underpayment_is_rejected(): void
    {
        $this->fake([
            'https://sandbox.cashfree.com/pg/links/parcel-42-ref' => Http::response([
                'link_status' => 'PAID', 'link_amount' => 250, 'link_amount_paid' => 1, 'cf_link_id' => 991,
            ]),
        ]);

        $this->withSession($this->pending('cashfree', ['link_id' => 'parcel-42-ref', 'expected' => 250.0]))
            ->postJson('/app/pay/verify', [])
            ->assertStatus(422);
    }

    public function test_cashfree_paid_link_verifies(): void
    {
        $this->fake([
            'https://sandbox.cashfree.com/pg/links/parcel-42-ref' => Http::response([
                'link_status' => 'PAID', 'link_amount' => 250, 'link_amount_paid' => 250, 'cf_link_id' => 991,
            ]),
        ]);

        $this->withSession($this->pending('cashfree', ['link_id' => 'parcel-42-ref', 'expected' => 250.0]))
            ->postJson('/app/pay/verify', [])
            ->assertOk();
    }

    public function test_phonepe_amount_mismatch_is_rejected(): void
    {
        $this->fake([
            'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token' => Http::response(['access_token' => 'pp']),
            'https://api-preprod.phonepe.com/apis/pg-sandbox/checkout/v2/order/parcel_42_ref/status' => Http::response([
                'state' => 'COMPLETED', 'amount' => 100, 'orderId' => 'OMO1',
            ]),
        ]);

        $this->withSession($this->pending('phonepe', ['order_id' => 'parcel_42_ref', 'minor' => 25000]))
            ->postJson('/app/pay/verify', [])
            ->assertStatus(422);
    }

    public function test_mercadopago_payment_id_comes_from_the_return_but_is_reread_from_the_api(): void
    {
        $this->fake([
            'https://api.mercadopago.com/v1/payments/12345678' => Http::response([
                'status' => 'approved', 'external_reference' => 'parcel_42_ref', 'transaction_amount' => 250,
            ]),
        ]);

        $this->withSession($this->pending('mercadopago', ['reference' => 'parcel_42_ref', 'expected' => 250.0, 'currency' => 'ARS']))
            ->postJson('/app/pay/verify', ['payment_id' => '12345678'])
            ->assertOk();

        $this->assertSame('12345678', session('booking_payment_verified.transaction_id'));
    }

    public function test_mercadopago_rejects_a_payment_for_another_reference(): void
    {
        $this->fake([
            'https://api.mercadopago.com/v1/payments/12345678' => Http::response([
                'status' => 'approved', 'external_reference' => 'their_ref', 'transaction_amount' => 250,
            ]),
        ]);

        $this->withSession($this->pending('mercadopago', ['reference' => 'parcel_42_ref', 'expected' => 250.0, 'currency' => 'ARS']))
            ->postJson('/app/pay/verify', ['payment_id' => '12345678'])
            ->assertStatus(422);
    }

    public function test_polling_gives_up_after_the_retry_cap_instead_of_spinning_forever(): void
    {
        // A confirmation that exists arrives in seconds; attempt 20 turns the
        // "not yet" into a final failure and burns the pending record.
        $this->fake();

        $this->withSession($this->pending('payfast', [
            'reference' => 'parcel_42_ref', 'value' => '250.00', 'verify_attempts' => 19,
        ]))
            ->postJson('/app/pay/verify', [])
            ->assertStatus(422)
            ->assertJsonPath('message', __('That payment has not completed.'));

        $this->assertNull(session('booking_payment_pending'), 'the pending record must be consumed');
    }

    public function test_each_202_advances_the_attempt_counter(): void
    {
        $this->fake();

        $this->withSession($this->pending('payfast', ['reference' => 'parcel_42_ref', 'value' => '250.00']))
            ->postJson('/app/pay/verify', [])
            ->assertStatus(202);

        $this->assertSame(1, session('booking_payment_pending.verify_attempts'));
    }

    public function test_payfast_verify_trusts_only_the_validated_itn(): void
    {
        $this->fake();

        // No ITN recorded yet → 202, pending kept.
        $this->withSession($this->pending('payfast', ['reference' => 'parcel_42_ref', 'value' => '250.00']))
            ->postJson('/app/pay/verify', [])
            ->assertStatus(202);

        // ITN recorded (as the webhook does after PayFast validates it).
        Cache::put(\App\Services\Payments\PayfastGateway::PAID_CACHE.'parcel_42_ref', [
            'pf_payment_id' => 'PF991', 'amount_gross' => '250.00',
        ], 600);

        $this->withSession($this->pending('payfast', ['reference' => 'parcel_42_ref', 'value' => '250.00']))
            ->postJson('/app/pay/verify', [])
            ->assertOk();

        $this->assertSame('PF991', session('booking_payment_verified.transaction_id'));
    }

    public function test_the_payfast_webhook_records_only_validated_notifications(): void
    {
        // PayFast answers INVALID for the first notification, VALID for the
        // second — only the validated one may be recorded.
        $this->fake([
            'https://sandbox.payfast.co.za/eng/query/validate' => Http::sequence()
                ->push('INVALID')
                ->push('VALID'),
        ]);

        $notification = [
            'm_payment_id' => 'parcel_42_ref', 'payment_status' => 'COMPLETE',
            'amount_gross' => '250.00', 'pf_payment_id' => 'PF991', 'merchant_id' => '10000100',
        ];

        $this->post('/webhooks/payfast', $notification)->assertOk();
        $this->assertNull(Cache::get(\App\Services\Payments\PayfastGateway::PAID_CACHE.'parcel_42_ref'));

        $this->post('/webhooks/payfast', $notification)->assertOk();
        $this->assertSame('PF991', Cache::get(\App\Services\Payments\PayfastGateway::PAID_CACHE.'parcel_42_ref')['pf_payment_id']);
    }

    public function test_redirect_gateways_are_flagged_for_the_popup_flow(): void
    {
        $this->fake();

        $methods = collect(app(\App\Services\Cabme\CatalogApi::class)->enabledPaymentMethods('topup'));

        $this->assertSame('redirect', $methods->firstWhere('label', 'Xendit')['flow']);
        $this->assertSame('redirect', $methods->firstWhere('label', 'Cashfree')['flow']);
        $this->assertSame('redirect', $methods->firstWhere('label', 'Instamojo')['flow']);
        $this->assertSame('redirect', $methods->firstWhere('label', 'PayMongo')['flow']);
        $this->assertSame('inline', $methods->firstWhere('label', 'Foloosi')['flow']);
        // Retired platform-wide: never offered, whatever the admin has enabled.
        $this->assertNull($methods->firstWhere('label', 'Paytm'));
    }

    public function test_instamojo_completed_request_with_matching_amount_verifies(): void
    {
        $this->fake([
            'https://test.instamojo.com/oauth2/token/' => Http::response(['access_token' => 'im-token']),
            'https://test.instamojo.com/v2/payment-requests/req_1/' => Http::response([
                'payment_request' => ['id' => 'req_1', 'status' => 'completed', 'amount' => 250,
                    'payments' => [['id' => 'MOJO991']]],
            ]),
        ]);

        $this->withSession($this->pending('instamojo', ['request_id' => 'req_1', 'expected' => 250.0]))
            ->postJson('/app/pay/verify', [])
            ->assertOk();

        $this->assertSame('MOJO991', session('booking_payment_verified.transaction_id'));
    }

    public function test_an_unpaid_instamojo_request_keeps_the_pending_record(): void
    {
        $this->fake([
            'https://test.instamojo.com/oauth2/token/' => Http::response(['access_token' => 'im-token']),
            'https://test.instamojo.com/v2/payment-requests/req_1/' => Http::response([
                'payment_request' => ['id' => 'req_1', 'status' => 'pending', 'amount' => 250],
            ]),
        ]);

        $this->withSession($this->pending('instamojo', ['request_id' => 'req_1', 'expected' => 250.0]))
            ->postJson('/app/pay/verify', [])
            ->assertStatus(202);

        $this->assertNotNull(session('booking_payment_pending'));
    }

    public function test_paymongo_paid_link_with_matching_centavos_verifies(): void
    {
        $this->fake([
            'https://api.paymongo.com/v1/links/link_1' => Http::response([
                'data' => ['id' => 'link_1', 'attributes' => ['status' => 'paid', 'amount' => 25000]],
            ]),
        ]);

        $this->withSession($this->pending('paymongo', ['link_id' => 'link_1', 'minor' => 25000]))
            ->postJson('/app/pay/verify', [])
            ->assertOk();
    }

    public function test_paymongo_underpayment_is_rejected(): void
    {
        $this->fake([
            'https://api.paymongo.com/v1/links/link_1' => Http::response([
                'data' => ['id' => 'link_1', 'attributes' => ['status' => 'paid', 'amount' => 100]],
            ]),
        ]);

        $this->withSession($this->pending('paymongo', ['link_id' => 'link_1', 'minor' => 25000]))
            ->postJson('/app/pay/verify', [])
            ->assertStatus(422);
    }

    public function test_foloosi_verifies_the_transaction_and_rejects_foreign_references(): void
    {
        $this->fake([
            'https://foloosi.com/aggregatorapi/web/transaction-detail/TXN991' => Http::response([
                'data' => ['status' => 'success', 'transaction_amount' => 250, 'reference_token' => 'ref-ours'],
            ]),
            'https://foloosi.com/aggregatorapi/web/transaction-detail/TXN666' => Http::response([
                'data' => ['status' => 'success', 'transaction_amount' => 250, 'reference_token' => 'ref-theirs'],
            ]),
        ]);

        $this->withSession($this->pending('foloosi', ['reference_token' => 'ref-ours', 'expected' => 250.0]))
            ->postJson('/app/pay/verify', ['transaction_no' => 'TXN991'])
            ->assertOk();

        $this->assertSame('TXN991', session('booking_payment_verified.transaction_id'));

        // A successful transaction belonging to a DIFFERENT charge is refused.
        $this->withSession($this->pending('foloosi', ['reference_token' => 'ref-ours', 'expected' => 250.0]))
            ->postJson('/app/pay/verify', ['transaction_no' => 'TXN666'])
            ->assertStatus(422);
    }
}
