<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEventData;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecordScenarioEvent
{
    public function bookingCompleted(Booking $booking, ?string $causationId, CarbonImmutable $occurredAt): ScenarioEvent
    {
        $booking->loadMissing(['client']);
        $data = new ScenarioEventData(
            eventType: ScenarioEventType::BookingCompleted,
            aggregateType: Booking::class,
            aggregateId: (string) $booking->getKey(),
            occurredAt: $occurredAt->utc(),
            payload: [
                'booking_id' => (int) $booking->getKey(),
                'client_id' => (int) $booking->client_id,
                'service_id' => (int) $booking->service_id,
                'specialist_id' => (int) $booking->specialist_id,
                'status' => $booking->status->value,
                'visit_format' => $booking->visit_format->value,
                'starts_at' => $booking->startsAtUtc()->toIso8601String(),
                'ends_at' => $booking->endsAtUtc()->toIso8601String(),
                'completed_at' => $occurredAt->utc()->toIso8601String(),
                'client_language' => $booking->client->language,
            ],
            idempotencyKey: 'booking.completed:'.$booking->organization_id.':'.$booking->getKey().':'.$booking->event_version,
            correlationId: 'booking:'.$booking->getKey(),
            causationId: $causationId,
        );

        return $this->record((int) $booking->organization_id, $data);
    }

    public function onboardingStarted(
        ClientOnboarding $onboarding,
        ?string $causationId,
        CarbonImmutable $occurredAt,
    ): ScenarioEvent {
        $onboarding->loadMissing(['client']);
        $data = new ScenarioEventData(
            eventType: ScenarioEventType::OnboardingStarted,
            aggregateType: ClientOnboarding::class,
            aggregateId: (string) $onboarding->getKey(),
            occurredAt: $occurredAt->utc(),
            payload: [
                'onboarding_id' => (int) $onboarding->getKey(),
                'client_id' => (int) $onboarding->client_id,
                'flow_version' => $onboarding->flow_version,
                'stage' => $onboarding->current_stage->value,
                'started_at' => $occurredAt->utc()->toIso8601String(),
                'client_language' => $onboarding->client->language,
            ],
            idempotencyKey: 'onboarding.started:'.$onboarding->organization_id.':'.$onboarding->getKey().':'.$onboarding->flow_version,
            correlationId: 'onboarding:'.$onboarding->getKey(),
            causationId: $causationId,
        );

        return $this->record((int) $onboarding->organization_id, $data);
    }

    private function record(int $organizationId, ScenarioEventData $data): ScenarioEvent
    {
        $timestamp = now();
        DB::table('scenario_events')->insertOrIgnore([
            'organization_id' => $organizationId,
            'event_name' => $data->eventType->value,
            'aggregate_type' => $data->aggregateType,
            'aggregate_id' => $data->aggregateId,
            'occurred_at' => $data->occurredAt,
            'payload' => json_encode($data->payload, JSON_THROW_ON_ERROR),
            'correlation_id' => $data->correlationId,
            'causation_id' => $data->causationId,
            'idempotency_key' => $data->idempotencyKey,
            'status' => ScenarioEventStatus::Pending->value,
            'attempt_count' => 0,
            'available_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return ScenarioEvent::query()
            ->where('organization_id', $organizationId)
            ->where('idempotency_key', $data->idempotencyKey)
            ->firstOrFail();
    }
}
