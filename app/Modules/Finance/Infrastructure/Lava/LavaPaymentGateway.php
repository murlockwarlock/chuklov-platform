<?php

namespace App\Modules\Finance\Infrastructure\Lava;

use App\Modules\Finance\Domain\Contracts\PaymentGateway;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\GatewayFailureEvidence;
use App\Modules\Finance\Domain\ValueObjects\GatewayInitiationRequest;
use App\Modules\Finance\Domain\ValueObjects\GatewayInitiationResult;
use App\Modules\Finance\Domain\ValueObjects\GatewayReconciliationResult;
use App\Modules\Finance\Domain\ValueObjects\GatewayRefundEvidence;
use App\Modules\Finance\Domain\ValueObjects\GatewayRefundRequest;
use App\Modules\Finance\Domain\ValueObjects\GatewayRefundResult;
use App\Modules\Finance\Domain\ValueObjects\GatewaySettlementEvidence;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Finance\Domain\ValueObjects\VerifiedGatewayFailure;
use App\Modules\Finance\Domain\ValueObjects\VerifiedGatewayRefund;
use App\Modules\Finance\Domain\ValueObjects\VerifiedGatewaySettlement;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class LavaPaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'lava';
    }

    public function initiate(GatewayInitiationRequest $request): GatewayInitiationResult
    {
        $this->assertSupportedCurrency($request->currency);
        if ($request->amountMinor <= 0 || $request->buyerEmail === null || $request->providerOfferId === null) {
            throw new InvalidArgumentException('Lava invoice data is incomplete.');
        }

        $payload = [
            'email' => $request->buyerEmail,
            'offerId' => $request->providerOfferId,
            'currency' => $request->currency->value,
            'amount' => Money::ofMinor($request->amountMinor, $request->currency)->toDecimalString(),
            'periodicity' => 'ONE_TIME',
        ];
        foreach ([
            'successful_return_url' => $request->successfulReturnUrl,
            'failure_return_url' => $request->failureReturnUrl,
            'cancel_return_url' => $request->cancelReturnUrl,
        ] as $key => $url) {
            if ($url !== null) {
                $payload[$key] = $url;
            }
        }

        $response = $this->client($request->organizationId)->post('/api/v3/invoice', $payload);
        if (! $response->successful()) {
            throw new RuntimeException('Lava invoice creation failed with status '.$response->status().'.');
        }

        $data = $response->json();
        $providerReference = is_array($data) ? ($data['id'] ?? null) : null;
        $checkoutUrl = is_array($data) ? ($data['paymentUrl'] ?? null) : null;
        if (! is_string($providerReference) || ! Str::isUuid($providerReference)) {
            throw new RuntimeException('Lava invoice response has no valid contract reference.');
        }
        if (! is_string($checkoutUrl) || ! $this->isHttpsUrl($checkoutUrl)) {
            throw new RuntimeException('Lava invoice response has no valid checkout URL.');
        }

        return new GatewayInitiationResult(
            gateway: $this->name(),
            providerReference: $providerReference,
            checkoutUrl: $checkoutUrl,
        );
    }

    public function refund(GatewayRefundRequest $request): GatewayRefundResult
    {
        throw new LogicException('Lava refund initiation is intentionally manual.');
    }

    public function verifyFailure(GatewayFailureEvidence $evidence): VerifiedGatewayFailure
    {
        throw new LogicException('Lava webhook verification uses the authenticated webhook adapter.');
    }

    public function verifySettlement(GatewaySettlementEvidence $evidence): VerifiedGatewaySettlement
    {
        throw new LogicException('Lava webhook verification uses the authenticated webhook adapter.');
    }

    public function verifyRefund(GatewayRefundEvidence $evidence): VerifiedGatewayRefund
    {
        throw new LogicException('Lava webhook verification uses the authenticated webhook adapter.');
    }

    public function reconcile(string $providerReference, int $organizationId): GatewayReconciliationResult
    {
        if (! Str::isUuid($providerReference)) {
            throw new InvalidArgumentException('The Lava contract reference is invalid.');
        }

        $response = $this->client($organizationId)->get('/api/v1/invoices/'.rawurlencode($providerReference));
        if (! $response->successful()) {
            throw new RuntimeException('Lava invoice lookup failed with status '.$response->status().'.');
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Lava invoice lookup returned an invalid response.');
        }
        $amountTotal = is_array($data['amountTotal'] ?? null) ? $data['amountTotal'] : [];
        $currencyValue = $amountTotal['currency'] ?? data_get($data, 'receipt.currency');
        $amountValue = $amountTotal['amount'] ?? data_get($data, 'receipt.amount');
        if (! is_string($currencyValue) || ! is_string($amountValue) && ! is_int($amountValue) && ! is_float($amountValue)) {
            throw new RuntimeException('Lava invoice lookup has no amount and currency.');
        }
        $currency = CurrencyCode::tryFrom($currencyValue);
        if ($currency === null) {
            throw new InvalidArgumentException('Lava returned an unsupported currency.');
        }
        $this->assertSupportedCurrency($currency);
        $amount = Money::fromDecimal($this->decimalString($amountValue), $currency);
        $status = match (strtolower((string) ($data['status'] ?? ''))) {
            'completed', 'success', 'paid' => 'settled',
            'failed', 'cancelled' => 'failed',
            default => 'pending',
        };

        return new GatewayReconciliationResult(
            providerReference: $providerReference,
            status: $status,
            amountMinor: $amount->minorUnits(),
            currency: $currency,
        );
    }

    private function client(int $organizationId): PendingRequest
    {
        $credential = OrganizationCredential::query()
            ->where('organization_id', $organizationId)
            ->where('provider', 'lava')
            ->where('credential_name', (string) config('payments.lava.credential_name', 'default'))
            ->where('status', CredentialStatus::Active->value)
            ->first();
        $apiKey = $credential?->credentials['api_key'] ?? null;
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new InvalidArgumentException('The active Lava API credential is not configured.');
        }

        return Http::baseUrl(rtrim((string) config('payments.lava.base_url', 'https://gate.lava.top'), '/'))
            ->acceptJson()
            ->withHeaders(['X-Api-Key' => $apiKey])
            ->timeout(max(1, (int) config('payments.lava.timeout_seconds', 10)));
    }

    private function assertSupportedCurrency(CurrencyCode $currency): void
    {
        if (! in_array($currency, [CurrencyCode::RUB, CurrencyCode::USD, CurrencyCode::EUR], true)) {
            throw new InvalidArgumentException('Lava supports only RUB, USD, and EUR for this integration.');
        }
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && strlen($url) <= 2048;
    }

    private function decimalString(int|float|string $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
