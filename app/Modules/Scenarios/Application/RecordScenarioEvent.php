<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEventData;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecordScenarioEvent
{
    public function b2bLeadSubmitted(B2bLead $lead, B2bSalesCall $salesCall, CarbonImmutable $occurredAt): ScenarioEvent
    {
        $data = new ScenarioEventData(
            eventType: ScenarioEventType::B2bLeadSubmitted,
            aggregateType: B2bLead::class,
            aggregateId: (string) $lead->getKey(),
            occurredAt: $occurredAt->utc(),
            payload: [
                'lead_id' => (int) $lead->getKey(),
                'client_id' => (int) $lead->client_id,
                'sales_call_id' => (int) $salesCall->getKey(),
                'specialist_id' => (int) $salesCall->specialist_id,
                'source' => $lead->source_channel->value,
                'starts_at' => $salesCall->startsAtUtc()->toIso8601String(),
                'ends_at' => $salesCall->endsAtUtc()->toIso8601String(),
                'schedule_timezone' => (string) $salesCall->schedule_timezone,
                'requested_timezone' => (string) $salesCall->requested_timezone,
            ],
            idempotencyKey: 'b2b.lead.submitted:'.$lead->organization_id.':'.$lead->getKey().':'.$lead->event_version,
            correlationId: 'b2b:lead:'.$lead->getKey(),
            causationId: null,
        );

        return $this->record((int) $lead->organization_id, $data);
    }

    public function b2bSalesCallReady(B2bSalesCall $salesCall, CarbonImmutable $occurredAt): ScenarioEvent
    {
        $data = new ScenarioEventData(
            eventType: ScenarioEventType::B2bSalesCallReady,
            aggregateType: B2bSalesCall::class,
            aggregateId: (string) $salesCall->getKey(),
            occurredAt: $occurredAt->utc(),
            payload: [
                'organization_id' => (int) $salesCall->organization_id,
                'sales_call_id' => (int) $salesCall->getKey(),
                'lead_id' => (int) $salesCall->lead_id,
                'client_id' => (int) $salesCall->client_id,
                'specialist_id' => (int) $salesCall->specialist_id,
                'event_version' => (int) $salesCall->event_version,
                'provider_sync_version' => (int) $salesCall->provider_sync_version,
                'provider_correlation_key' => $salesCall->provider_correlation_key,
                'meeting_mode' => $salesCall->meeting_mode->value,
                'starts_at' => $salesCall->startsAtUtc()->toIso8601String(),
                'ends_at' => $salesCall->endsAtUtc()->toIso8601String(),
                'schedule_timezone' => (string) $salesCall->schedule_timezone,
            ],
            idempotencyKey: 'b2b.sales_call.ready:'.$salesCall->organization_id.':'.$salesCall->getKey().':'.$salesCall->provider_sync_version,
            correlationId: 'b2b:sales-call:'.$salesCall->getKey(),
            causationId: null,
        );

        return $this->record((int) $salesCall->organization_id, $data);
    }

    public function surveyCompleted(SurveyAttempt $attempt, SurveyReport $report, CarbonImmutable $occurredAt): ScenarioEvent
    {
        $data = new ScenarioEventData(
            eventType: ScenarioEventType::SurveyCompleted,
            aggregateType: SurveyAttempt::class,
            aggregateId: (string) $attempt->getKey(),
            occurredAt: $occurredAt->utc(),
            payload: [
                'attempt_id' => (int) $attempt->getKey(),
                'report_id' => (int) $report->getKey(),
                'client_id' => (int) $attempt->client_id,
                'survey_definition_id' => (int) $attempt->survey_definition_id,
                'survey_version_id' => (int) $attempt->survey_version_id,
                'completed_at' => $occurredAt->utc()->toIso8601String(),
            ],
            idempotencyKey: 'survey.completed:'.$attempt->organization_id.':'.$attempt->getKey(),
            correlationId: 'survey:attempt:'.$attempt->getKey(),
            causationId: null,
        );

        return $this->record((int) $attempt->organization_id, $data);
    }

    public function testStagnationDetected(SurveyAttempt $current, SurveyAttempt $previous, CarbonImmutable $occurredAt): ScenarioEvent
    {
        $data = new ScenarioEventData(
            eventType: ScenarioEventType::TestStagnationDetected,
            aggregateType: SurveyAttempt::class,
            aggregateId: (string) $current->getKey(),
            occurredAt: $occurredAt->utc(),
            payload: [
                'attempt_id' => (int) $current->getKey(),
                'previous_attempt_id' => (int) $previous->getKey(),
                'client_id' => (int) $current->client_id,
                'survey_definition_id' => (int) $current->survey_definition_id,
                'survey_version_id' => (int) $current->survey_version_id,
            ],
            idempotencyKey: 'TEST_STAGNATION_DETECTED:'.$current->organization_id.':'.$current->getKey(),
            correlationId: 'survey:attempt:'.$current->getKey(),
            causationId: null,
        );

        return $this->record((int) $current->organization_id, $data);
    }

    public function bookingCreated(Booking $booking, ?string $causationId, CarbonImmutable $occurredAt): ScenarioEvent
    {
        return $this->bookingLifecycleEvent($booking, ScenarioEventType::BookingCreated, $causationId, $occurredAt);
    }

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

    public function bookingConfirmed(Booking $booking, ?string $causationId, CarbonImmutable $occurredAt): ScenarioEvent
    {
        return $this->bookingLifecycleEvent($booking, ScenarioEventType::BookingConfirmed, $causationId, $occurredAt);
    }

    public function bookingRescheduled(Booking $booking, ?string $causationId, CarbonImmutable $occurredAt): ScenarioEvent
    {
        return $this->bookingLifecycleEvent($booking, ScenarioEventType::BookingRescheduled, $causationId, $occurredAt);
    }

    public function bookingCancelled(Booking $booking, ?string $causationId, CarbonImmutable $occurredAt): ScenarioEvent
    {
        return $this->bookingLifecycleEvent($booking, ScenarioEventType::BookingCancelled, $causationId, $occurredAt);
    }

    private function bookingLifecycleEvent(
        Booking $booking,
        ScenarioEventType $eventType,
        ?string $causationId,
        CarbonImmutable $occurredAt,
    ): ScenarioEvent {
        $booking->loadMissing(['client']);
        $data = new ScenarioEventData(
            eventType: $eventType,
            aggregateType: Booking::class,
            aggregateId: (string) $booking->getKey(),
            occurredAt: $occurredAt->utc(),
            payload: $this->bookingLifecyclePayload($booking),
            idempotencyKey: $eventType->value.':'.$booking->organization_id.':'.$booking->getKey().':'.$booking->event_version,
            correlationId: 'booking:'.$booking->getKey(),
            causationId: $causationId,
        );

        return $this->record((int) $booking->organization_id, $data);
    }

    /** @return array<string, int|string|null> */
    private function bookingLifecyclePayload(Booking $booking): array
    {
        return [
            'organization_id' => (int) $booking->organization_id,
            'booking_id' => (int) $booking->getKey(),
            'client_id' => (int) $booking->client_id,
            'service_id' => (int) $booking->service_id,
            'specialist_id' => (int) $booking->specialist_id,
            'event_version' => (int) $booking->event_version,
            'status' => $booking->status->value,
            'visit_format' => $booking->visit_format->value,
            'starts_at' => $booking->startsAtUtc()->toIso8601String(),
            'ends_at' => $booking->endsAtUtc()->toIso8601String(),
            'schedule_timezone' => (string) $booking->schedule_timezone,
            'client_timezone' => $booking->client_timezone,
            'meeting_link_mode' => $booking->meeting_link_mode?->value,
            'client_language' => $booking->client->language,
        ];
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

    public function financialObligationCreated(
        FinancialObligation $obligation,
        ?string $causationId,
        CarbonImmutable $occurredAt,
    ): ScenarioEvent {
        $client = $obligation->client()->firstOrFail();
        $data = new ScenarioEventData(
            eventType: ScenarioEventType::FinancialObligationCreated,
            aggregateType: FinancialObligation::class,
            aggregateId: (string) $obligation->getKey(),
            occurredAt: $occurredAt->utc(),
            payload: [
                'obligation_id' => (int) $obligation->getKey(),
                'client_id' => (int) $obligation->client_id,
                'booking_id' => (int) $obligation->booking_id,
                'service_id' => (int) $obligation->service_id,
                'currency' => $obligation->currency->value,
                'client_language' => $client->language,
            ],
            idempotencyKey: 'finance.obligation.created:'.$obligation->organization_id.':'.$obligation->getKey(),
            correlationId: 'finance:obligation:'.$obligation->getKey(),
            causationId: $causationId,
        );

        return $this->record((int) $obligation->organization_id, $data);
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
