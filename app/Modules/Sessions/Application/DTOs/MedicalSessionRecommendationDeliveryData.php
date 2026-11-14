<?php

namespace App\Modules\Sessions\Application\DTOs;

use App\Modules\Sessions\Application\Models\MedicalSessionRecommendationDelivery;
use DateTimeInterface;

final readonly class MedicalSessionRecommendationDeliveryData
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $clientId,
        public int $sessionId,
        public int $recommendationRevision,
        public string $channel,
        public string $status,
        public string $outcome,
        public string $idempotencyKey,
        public string $contentHash,
        public ?string $providerReference,
        public ?string $errorCode,
        public ?DateTimeInterface $claimedAt,
        public ?DateTimeInterface $deliveredAt,
        public ?DateTimeInterface $failedAt,
    ) {}

    public static function fromModel(MedicalSessionRecommendationDelivery $delivery, ?string $outcome = null): self
    {
        $status = (string) $delivery->getAttribute('status');
        $outcome ??= match ($status) {
            'delivered' => 'delivered',
            'unknown' => 'unknown',
            'claimed' => 'in_flight',
            'unavailable' => 'unavailable',
            'suppressed' => 'suppressed',
            default => 'permanent_failure',
        };

        return new self(
            id: (int) $delivery->getKey(),
            organizationId: (int) $delivery->getAttribute('organization_id'),
            clientId: (int) $delivery->getAttribute('client_id'),
            sessionId: (int) $delivery->getAttribute('medical_session_id'),
            recommendationRevision: (int) $delivery->getAttribute('recommendation_revision'),
            channel: (string) $delivery->getAttribute('channel'),
            status: $status,
            outcome: $outcome,
            idempotencyKey: (string) $delivery->getAttribute('idempotency_key'),
            contentHash: (string) $delivery->getAttribute('content_hash'),
            providerReference: self::nullableString($delivery->getAttribute('provider_reference')),
            errorCode: self::nullableString($delivery->getAttribute('error_code')),
            claimedAt: $delivery->getAttribute('claimed_at'),
            deliveredAt: $delivery->getAttribute('delivered_at'),
            failedAt: $delivery->getAttribute('failed_at'),
        );
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered' && $this->outcome === 'delivered';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organizationId,
            'client_id' => $this->clientId,
            'medical_session_id' => $this->sessionId,
            'recommendation_revision' => $this->recommendationRevision,
            'channel' => $this->channel,
            'status' => $this->status,
            'outcome' => $this->outcome,
            'idempotency_key' => $this->idempotencyKey,
            'content_hash' => $this->contentHash,
            'provider_reference' => $this->providerReference,
            'error_code' => $this->errorCode,
            'claimed_at' => $this->claimedAt?->format(DateTimeInterface::ATOM),
            'delivered_at' => $this->deliveredAt?->format(DateTimeInterface::ATOM),
            'failed_at' => $this->failedAt?->format(DateTimeInterface::ATOM),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
