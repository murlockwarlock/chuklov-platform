<?php

namespace App\Modules\Finance\Domain\Services;

use JsonException;

final class PaymentGatewayEventIdentity
{
    public static function paymentKey(string $gateway, string $eventType, string $providerReference): string
    {
        return $gateway.':'.$eventType.':'.$providerReference;
    }

    public static function providerKey(string $gateway, string $eventType, string $providerEventId): string
    {
        return $gateway.':'.$eventType.':'.$providerEventId;
    }

    /** @param array<string, mixed> $payload */
    public static function payloadHash(array $payload): string
    {
        return hash('sha256', self::canonicalJson($payload));
    }

    /** @param array<string, mixed> $payload */
    private static function canonicalJson(array $payload): string
    {
        self::sortKeys($payload);

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('The provider event payload is not serializable.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function sortKeys(array &$payload): void
    {
        ksort($payload);
        foreach ($payload as &$value) {
            if (is_array($value) && ! array_is_list($value)) {
                self::sortKeys($value);
            }
        }
    }
}
