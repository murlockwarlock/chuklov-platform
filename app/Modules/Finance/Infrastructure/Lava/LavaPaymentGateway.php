<?php

namespace App\Modules\Finance\Infrastructure\Lava;

use App\Modules\Finance\Domain\Contracts\PaymentGateway;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Exceptions\PaymentGatewayConfigurationException;
use App\Modules\Finance\Domain\Exceptions\PaymentGatewayProviderException;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
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
use Illuminate\Http\Client\ConnectionException;
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
        if ($request->amountMinor <= 0
            || $request->buyerEmail === null
            || filter_var($request->buyerEmail, FILTER_VALIDATE_EMAIL) === false
            || mb_strlen($request->buyerEmail) > 320
            || $request->providerOfferId === null
            || ! Str::isUuid(trim($request->providerOfferId))) {
            throw new PaymentGatewayConfigurationException(
                failureReason: 'invalid_payment_request',
                safeClientMessage: 'Онлайн-оплата сейчас временно недоступна. Попробуйте позже.',
                notifyOperations: true,
                safeOperatorMessage: 'Lava invoice configuration is incomplete.',
            );
        }

        $payload = [
            'email' => $request->buyerEmail,
            'offerId' => trim($request->providerOfferId),
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

        try {
            $response = $this->client($request->organizationId)->post('/api/v3/invoice', $payload);
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayProviderException(
                failureReason: 'provider_timeout',
                safeClientMessage: 'Не удалось открыть страницу оплаты. Если деньги уже списались, не оплачивайте повторно — мы проверим платёж.',
                notifyOperations: true,
                safeOperatorMessage: 'Lava did not respond while creating an invoice.',
                previous: $exception,
            );
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new PaymentGatewayConfigurationException(
                failureReason: 'invalid_credential',
                safeClientMessage: 'Онлайн-оплата сейчас временно недоступна. Попробуйте позже.',
                notifyOperations: true,
                safeOperatorMessage: 'Lava rejected the configured API credential.',
            );
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new PaymentGatewayProviderException(
                failureReason: 'provider_unavailable',
                safeClientMessage: 'Не удалось открыть страницу оплаты. Если деньги уже списались, не оплачивайте повторно — мы проверим платёж.',
                notifyOperations: true,
                safeOperatorMessage: 'Lava was temporarily unavailable while creating an invoice.',
            );
        }

        if ($response->status() === 400 || $response->status() === 422) {
            throw new PaymentGatewayConfigurationException(
                failureReason: 'invalid_offer',
                safeClientMessage: 'Онлайн-оплата сейчас временно недоступна. Попробуйте позже.',
                notifyOperations: true,
                safeOperatorMessage: 'Lava rejected the configured offer or invoice parameters.',
            );
        }

        if (! $response->successful()) {
            throw new PaymentGatewayProviderException(
                failureReason: 'provider_rejected_request',
                safeClientMessage: 'Не удалось открыть страницу оплаты. Если деньги уже списались, не оплачивайте повторно — мы проверим платёж.',
                notifyOperations: true,
                safeOperatorMessage: 'Lava rejected invoice creation.',
            );
        }

        $data = $response->json();
        $providerReference = is_array($data) ? ($data['id'] ?? null) : null;
        $checkoutUrl = is_array($data) ? ($data['paymentUrl'] ?? null) : null;
        if (! is_string($providerReference) || ! Str::isUuid($providerReference)) {
            throw new PaymentGatewayProviderException(
                failureReason: 'invalid_provider_response',
                safeClientMessage: 'Не удалось открыть страницу оплаты. Если деньги уже списались, не оплачивайте повторно — мы проверим платёж.',
                notifyOperations: true,
                safeOperatorMessage: 'Lava invoice response has no valid contract reference.',
            );
        }
        if (! is_string($checkoutUrl) || ! $this->isHttpsUrl($checkoutUrl)) {
            throw new PaymentGatewayProviderException(
                failureReason: 'invalid_provider_response',
                safeClientMessage: 'Не удалось открыть страницу оплаты. Если деньги уже списались, не оплачивайте повторно — мы проверим платёж.',
                notifyOperations: true,
                safeOperatorMessage: 'Lava invoice response has no valid checkout URL.',
            );
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
        if (array_key_exists('id', $data)
            && (! is_string($data['id']) || ! hash_equals($providerReference, $data['id']))) {
            throw new RuntimeException('Lava invoice lookup returned a different contract reference.');
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
        $decimal = $this->decimalString($amountValue, $currency);
        if ($decimal === null) {
            throw new RuntimeException('Lava invoice lookup returned an amount with unsupported precision.');
        }
        try {
            $amount = Money::fromDecimal($decimal, $currency);
            $amount->assertPositive();
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Lava invoice lookup returned an invalid amount.', previous: $exception);
        }
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
            throw new PaymentGatewayConfigurationException(
                failureReason: 'missing_credential',
                safeClientMessage: 'Онлайн-оплата сейчас временно недоступна. Попробуйте позже.',
                notifyOperations: true,
                safeOperatorMessage: 'The active Lava API credential is not configured.',
            );
        }

        $baseUrl = rtrim((string) config('payments.lava.base_url', 'https://gate.lava.top'), '/');
        $parts = parse_url($baseUrl);
        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            throw new PaymentGatewayConfigurationException(
                failureReason: 'invalid_configuration',
                safeClientMessage: 'Онлайн-оплата сейчас временно недоступна. Попробуйте позже.',
                notifyOperations: true,
                safeOperatorMessage: 'The Lava API base URL must use HTTPS.',
            );
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withHeaders(['X-Api-Key' => $apiKey])
            ->timeout(max(1, (int) config('payments.lava.timeout_seconds', 10)));
    }

    private function assertSupportedCurrency(CurrencyCode $currency): void
    {
        if (! in_array($currency, [CurrencyCode::RUB, CurrencyCode::USD, CurrencyCode::EUR], true)) {
            throw new PaymentGatewayConfigurationException(
                failureReason: 'unsupported_currency',
                safeClientMessage: 'Онлайн-оплата сейчас временно недоступна. Попробуйте позже.',
                notifyOperations: true,
                safeOperatorMessage: 'Lava supports only RUB, USD, and EUR for this integration.',
            );
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

    private function decimalString(int|float|string $value, CurrencyCode $currency): ?string
    {
        $scale = app(CurrencyCatalog::class)->scale($currency);
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $value = trim($value);
            if (preg_match('/^-?(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $value, $matches) !== 1) {
                return null;
            }

            $fraction = rtrim($matches[2] ?? '', '0');
            if (strlen($fraction) > $scale) {
                return null;
            }

            return $value;
        }

        if (! is_finite($value)) {
            return null;
        }

        $formatted = number_format($value, $scale, '.', '');
        if ((float) $formatted !== $value) {
            return null;
        }

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
