<?php

namespace App\Modules\Integration\Application;

use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Integration\Domain\ValueObjects\IntegrationEventData;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RecordIntegrationEvent
{
    public function handle(Organization $organization, IntegrationEventData $data): IntegrationEvent
    {
        if ($data->aggregateId < 1 || trim($data->aggregateType) === '' || trim($data->idempotencyKey) === '') {
            throw new RuntimeException('Integration event identity is invalid.');
        }

        $payload = json_encode($data->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $attributes = [
            'organization_id' => $organization->getKey(),
            'event_type' => $data->eventType->value,
            'aggregate_type' => $data->aggregateType,
            'aggregate_id' => $data->aggregateId,
            'idempotency_key' => $data->idempotencyKey,
            'payload' => $payload,
            'status' => 'pending',
            'attempt_count' => 0,
            'occurred_at' => $data->occurredAt,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            DB::table('integration_events')->insertOrIgnore($attributes);
        } catch (UniqueConstraintViolationException) {
        }

        $event = IntegrationEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('idempotency_key', $data->idempotencyKey)
            ->lockForUpdate()
            ->firstOrFail();

        if ($event->getRawOriginal('event_type') !== $data->eventType->value
            || $event->aggregate_type !== $data->aggregateType
            || (int) $event->aggregate_id !== $data->aggregateId
            || json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !== $payload) {
            throw new RuntimeException('Integration event idempotency key was reused for different evidence.');
        }

        return $event;
    }
}
