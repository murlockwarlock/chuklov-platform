<?php

namespace App\Modules\Finance\Infrastructure\Lava;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\PaymentGatewayEventType;
use App\Modules\Finance\Domain\Services\PaymentGatewayEventIdentity;
use App\Modules\Finance\Domain\ValueObjects\Money;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class LavaWebhookParser
{
    private const PAYMENT_EVENTS = ['payment.success', 'payment.failed'];

    private const RECONCILIATION_EVENTS = ['refund.success', 'chargeback.initiated'];

    public function parse(array $payload): LavaWebhookEvent
    {
        $eventType = $this->eventType($payload);
        if (in_array($eventType, self::RECONCILIATION_EVENTS, true)) {
            return $this->envelopeEvent($eventType, $payload);
        }

        $contractId = $this->optionalUuid($payload['contractId'] ?? null);
        if (in_array($eventType, self::PAYMENT_EVENTS, true) && $contractId === null) {
            throw new InvalidArgumentException('Lava payment webhook has no valid contract reference.');
        }

        $money = $this->money($payload['amount'] ?? null, $payload['currency'] ?? null);
        $normalizedType = match ($eventType) {
            'payment.success' => PaymentGatewayEventType::Settlement,
            'payment.failed' => PaymentGatewayEventType::Failure,
            default => PaymentGatewayEventType::Unknown,
        };
        $providerReference = $contractId;
        $providerEventKey = $contractId !== null
            ? PaymentGatewayEventIdentity::paymentKey('lava', $eventType, $contractId)
            : 'lava:unknown:'.PaymentGatewayEventIdentity::payloadHash($payload);

        return new LavaWebhookEvent(
            eventType: $eventType,
            normalizedType: $normalizedType,
            providerEventKey: $providerEventKey,
            providerEventId: null,
            providerReference: $providerReference,
            amountMinor: $money?->minorUnits(),
            currency: $money?->currency(),
            canAutomaticLink: in_array($eventType, self::PAYMENT_EVENTS, true) && $money !== null,
            payload: $payload,
        );
    }

    private function envelopeEvent(string $eventType, array $payload): LavaWebhookEvent
    {
        $eventId = $this->optionalUuid($payload['event_id'] ?? null);
        if ($eventId === null) {
            throw new InvalidArgumentException('Lava reconciliation webhook has no valid event id.');
        }

        $data = $payload['data'] ?? null;
        $data = is_array($data) ? $data : [];
        $money = $this->money($data['amount'] ?? null, $data['currency'] ?? null);

        return new LavaWebhookEvent(
            eventType: $eventType,
            normalizedType: $eventType === 'refund.success'
                ? PaymentGatewayEventType::Refund
                : PaymentGatewayEventType::Chargeback,
            providerEventKey: $eventId,
            providerEventId: $eventId,
            providerReference: null,
            amountMinor: $money?->minorUnits(),
            currency: $money?->currency(),
            canAutomaticLink: false,
            payload: $payload,
        );
    }

    private function eventType(array $payload): string
    {
        $eventType = $payload['eventType'] ?? $payload['event_type'] ?? null;
        if (! is_string($eventType) || trim($eventType) === '' || strlen($eventType) > 80) {
            throw new InvalidArgumentException('Lava webhook event type is invalid.');
        }

        return trim($eventType);
    }

    private function optionalUuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function money(mixed $amount, mixed $currencyValue): ?Money
    {
        if (! is_string($currencyValue)) {
            return null;
        }
        $currency = CurrencyCode::tryFrom($currencyValue);
        if ($currency === null || ! in_array($currency, [CurrencyCode::RUB, CurrencyCode::USD, CurrencyCode::EUR], true)) {
            return null;
        }
        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            return null;
        }

        try {
            return Money::fromDecimal($this->decimalString($amount), $currency);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function decimalString(int|float|string $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
