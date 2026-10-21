<?php

namespace App\Services\Payments;

use App\Support\CabmeSettings;
use App\Support\GatewayCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MTN Mobile Money collections. There is no page to open: request-to-pay
 * prompts the rider's PHONE, and the browser polls verify() until the
 * request is approved (SUCCESSFUL) or dies (FAILED/REJECTED/TIMEOUT). The
 * amount is fixed server-side in the request; the status body is checked
 * against it before anything is credited.
 */
class MtnMomoGateway
{
    private const BASE = 'https://sandbox.momodeveloper.mtn.com';

    public function __construct(
        private readonly AdminGatewayCredentials $creds,
        private readonly CabmeSettings $settings,
    ) {
    }

    private function row(): array
    {
        return $this->creds->row('mtn') ?? [];
    }

    private function sandbox(): bool
    {
        return ($this->row()['isSandboxEnabled'] ?? 'true') === 'true';
    }

    private function targetEnvironment(): string
    {
        return $this->sandbox() ? 'sandbox' : (string) ($this->row()['target_environment'] ?: 'mtnuganda');
    }

    public function configured(): bool
    {
        return strlen((string) ($this->row()['key'] ?? '')) > 8;
    }

    /** @return array{client:array,pending:array}|array{error:string}|null */
    public function start(float $amount, string $reference, array $rider): ?array
    {
        $phone = preg_replace('/\D/', '', (string) ($rider['phone'] ?? ''));

        if (strlen($phone) < 8) {
            return ['error' => __('Add your mobile number to your profile to pay with MTN MoMo.')];
        }

        $subscriptionKey = (string) $this->row()['key'];
        $currency = $this->sandbox()
            ? 'EUR'
            : GatewayCurrency::chargeCurrency('mtnmomo', $this->settings->get('currency_code', 'USD'));
        $value = (string) (int) round($amount);

        try {
            // Sandbox API users are throwaway; goride provisions one per
            // charge and it works reliably, so mirror that.
            $apiUser = (string) Str::uuid();

            $provision = Http::withHeaders([
                'X-Reference-Id' => $apiUser,
                'Ocp-Apim-Subscription-Key' => $subscriptionKey,
            ])->timeout(20)->post(self::BASE.'/v1_0/apiuser', [
                'providerCallbackHost' => parse_url(config('app.url'), PHP_URL_HOST) ?: 'cabme.app',
            ]);

            if ($provision->status() !== 201) {
                return ['error' => 'MTN MoMo: '.__('Could not reach the payment provider.')];
            }

            $apiKey = (string) Http::withHeaders(['Ocp-Apim-Subscription-Key' => $subscriptionKey])
                ->timeout(20)->post(self::BASE."/v1_0/apiuser/$apiUser/apikey")->json('apiKey');

            $token = (string) Http::withBasicAuth($apiUser, $apiKey)
                ->withHeaders(['Ocp-Apim-Subscription-Key' => $subscriptionKey])
                ->timeout(20)->post(self::BASE.'/collection/token/')->json('access_token');

            if ($apiKey === '' || $token === '') {
                return ['error' => 'MTN MoMo: '.__('Could not reach the payment provider.')];
            }

            $referenceId = (string) Str::uuid();

            $pay = Http::withToken($token)->withHeaders([
                'X-Reference-Id' => $referenceId,
                'X-Target-Environment' => $this->targetEnvironment(),
                'Ocp-Apim-Subscription-Key' => $subscriptionKey,
            ])->timeout(25)->post(self::BASE.'/collection/v1_0/requesttopay', [
                'amount' => $value,
                'currency' => $currency,
                'externalId' => $reference,
                'payer' => ['partyIdType' => 'MSISDN', 'partyId' => $phone],
                'payerMessage' => config('app.name').' payment',
                'payeeNote' => config('app.name'),
            ]);

            if ($pay->status() !== 202) {
                Log::warning('MTN MoMo request-to-pay rejected', ['body' => substr($pay->body(), 0, 300)]);

                return null;
            }
        } catch (\Throwable $e) {
            Log::warning('MTN MoMo unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        return [
            'client' => ['gateway' => 'mtnmomo', 'flow' => 'poll'],
            'pending' => [
                'reference_id' => $referenceId,
                'access_token' => $token,
                'value' => $value,
                'currency' => $currency,
            ],
        ];
    }

    /** @return array{ok:bool,error:?string,payment_id:?string,retry?:bool} */
    public function verify(Request $request, array $pending): array
    {
        try {
            $response = Http::withToken((string) $pending['access_token'])->withHeaders([
                'X-Target-Environment' => $this->targetEnvironment(),
                'Ocp-Apim-Subscription-Key' => (string) ($this->row()['key'] ?? ''),
            ])->timeout(20)->get(self::BASE.'/collection/v1_0/requesttopay/'.$pending['reference_id']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach the payment provider.'), 'payment_id' => null];
        }

        $status = (string) $response->json('status', '');

        if ($status === 'PENDING' || $status === '') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null, 'retry' => true];
        }

        if ($status !== 'SUCCESSFUL') {
            return ['ok' => false, 'error' => __('That payment was not completed.'), 'payment_id' => null];
        }

        if ((string) $response->json('amount') !== (string) $pending['value']) {
            return ['ok' => false, 'error' => __('The paid amount does not match this order.'), 'payment_id' => null];
        }

        return ['ok' => true, 'error' => null, 'payment_id' => (string) ($response->json('financialTransactionId') ?: $pending['reference_id'])];
    }
}
