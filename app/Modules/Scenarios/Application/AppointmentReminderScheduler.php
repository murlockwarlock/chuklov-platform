<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Models\AppointmentReminder;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioDelivery;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipient;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AppointmentReminderScheduler
{
    public function __construct(
        private readonly ScenarioContextFactory $contextFactory,
        private readonly EnsureAppointmentReminderDefaults $defaults,
    ) {}

    public function schedule(Booking $booking, ScenarioEvent $event): void
    {
        DB::transaction(function () use ($booking, $event): void {
            $this->scheduleWithinTransaction($booking, $event);
        }, attempts: 3);
    }

    private function scheduleWithinTransaction(Booking $booking, ScenarioEvent $event): void
    {
        $organization = Organization::query()->findOrFail($booking->organization_id);

        if (! AppointmentReminder::query()->where('organization_id', $organization->getKey())->exists()) {
            $this->defaults->handle($organization);
        }

        $booking = Booking::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($booking->getKey())
            ->with(['client', 'service', 'specialist'])
            ->firstOrFail();

        if ($booking->status !== BookingStatus::Confirmed) {
            return;
        }

        $this->cancelStaleForBooking(
            organizationId: (int) $organization->getKey(),
            bookingId: (int) $booking->getKey(),
            eventId: (int) $event->getKey(),
            bookingStartsAt: $booking->startsAtUtc(),
        );

        $reminders = AppointmentReminder::query()
            ->where('organization_id', $organization->getKey())
            ->where('is_enabled', true)
            ->orderBy('recipient_type')
            ->orderBy('offset_value')
            ->orderBy('offset_unit')
            ->get();
        $now = CarbonImmutable::now()->utc();

        foreach ($reminders as $reminder) {
            $scheduledFor = $booking->startsAtUtc()->subSeconds(
                $reminder->offset_unit->toSeconds($reminder->offset_value),
            );
            if ($scheduledFor->lessThanOrEqualTo($now)) {
                continue;
            }

            $recipient = $this->recipient($booking, $reminder->recipient_type);
            if ($recipient === null) {
                continue;
            }

            $format = $booking->visit_format->value;
            $template = $this->template(
                (int) $organization->getKey(),
                $reminder->recipient_type,
                $format,
                $recipient->locale,
            );
            $rule = ScenarioRule::query()
                ->where('organization_id', $organization->getKey())
                ->where('rule_key', $this->ruleKey($reminder->recipient_type, $format))
                ->where('system_managed', true)
                ->where('is_enabled', true)
                ->first();
            if ($template === null || $rule === null) {
                continue;
            }

            $context = $this->contextFactory->evaluationContext($event, $scheduledFor);
            $renderContext = $this->contextFactory->renderContext($context, $recipient);
            $renderContext['booking']['reminder_offset_label'] = $this->offsetLabel(
                $reminder->offset_value,
                $reminder->offset_unit,
            );
            $materializationKey = hash('sha256', implode('|', [
                $organization->getKey(),
                'appointment-reminder',
                $booking->getKey(),
                $event->getKey(),
                $reminder->getKey(),
                $recipient->key(),
            ]));
            $action = ScenarioAction::query()
                ->where('organization_id', $organization->getKey())
                ->where('materialization_key', $materializationKey)
                ->lockForUpdate()
                ->first();

            if ($action !== null) {
                continue;
            }

            $timestamp = now();
            DB::table('scenario_actions')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'scenario_event_id' => $event->getKey(),
                'scenario_rule_id' => $rule->getKey(),
                'kind' => 'appointment_reminder',
                'appointment_reminder_id' => $reminder->getKey(),
                'booking_id' => $booking->getKey(),
                'booking_starts_at' => $booking->startsAtUtc(),
                'recipient_type' => $recipient->type,
                'client_id' => $recipient->clientId,
                'recipient_user_id' => $recipient->userId,
                'template_version_id' => $template->getKey(),
                'trigger_event' => $event->event_name->value,
                'rule_version' => $rule->version,
                'condition_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
                'sequence_number' => 1,
                'max_occurrences' => 1,
                'repeat_interval_value' => null,
                'repeat_interval_unit' => null,
                'purpose' => $rule->purpose->value,
                'channel_priority' => json_encode(['telegram'], JSON_THROW_ON_ERROR),
                'render_context' => json_encode($renderContext, JSON_THROW_ON_ERROR),
                'materialization_key' => $materializationKey,
                'scheduled_for' => $scheduledFor,
                'status' => ScenarioActionStatus::Scheduled->value,
                'attempt_count' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $actionId = DB::table('scenario_actions')
                ->where('organization_id', $organization->getKey())
                ->where('materialization_key', $materializationKey)
                ->value('id');

            if ($actionId === null) {
                continue;
            }

            DB::table('scenario_deliveries')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'scenario_action_id' => $actionId,
                'channel' => 'telegram',
                'priority' => 1,
                'status' => ScenarioDeliveryStatus::Pending->value,
                'idempotency_key' => hash('sha256', implode('|', [$organization->getKey(), $actionId, 'telegram'])),
                'attempt_count' => 0,
                'next_attempt_at' => $scheduledFor,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    private function cancelStaleForBooking(int $organizationId, int $bookingId, int $eventId, CarbonImmutable $bookingStartsAt): void
    {
        $actionIds = ScenarioAction::query()
            ->where('organization_id', $organizationId)
            ->where('booking_id', $bookingId)
            ->where('kind', 'appointment_reminder')
            ->whereIn('status', [ScenarioActionStatus::Scheduled, ScenarioActionStatus::Retryable])
            ->where(function ($query) use ($eventId, $bookingStartsAt): void {
                $query
                    ->where('scenario_event_id', '!=', $eventId)
                    ->orWhere('booking_starts_at', '!=', $bookingStartsAt);
            })
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id);

        $this->cancelActionIds($organizationId, $actionIds->all());
    }

    public function cancelForBooking(int $organizationId, int $bookingId): void
    {
        if (DB::transactionLevel() === 0) {
            DB::transaction(function () use ($organizationId, $bookingId): void {
                $this->cancelForBooking($organizationId, $bookingId);
            }, attempts: 3);

            return;
        }

        $actionIds = ScenarioAction::query()
            ->where('organization_id', $organizationId)
            ->where('booking_id', $bookingId)
            ->where('kind', 'appointment_reminder')
            ->whereIn('status', [ScenarioActionStatus::Scheduled, ScenarioActionStatus::Retryable])
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id);

        if ($actionIds->isEmpty()) {
            return;
        }

        $this->cancelActionIds($organizationId, $actionIds->all());
    }

    /** @param array<int, int|string> $actionIds */
    private function cancelActionIds(int $organizationId, array $actionIds): void
    {
        if ($actionIds === []) {
            return;
        }

        ScenarioDelivery::query()
            ->where('organization_id', $organizationId)
            ->whereIn('scenario_action_id', $actionIds)
            ->whereIn('status', [ScenarioDeliveryStatus::Pending, ScenarioDeliveryStatus::Retryable])
            ->update([
                'status' => ScenarioDeliveryStatus::Suppressed->value,
                'next_attempt_at' => null,
                'terminal_reason' => 'booking_changed',
                'last_error_code' => 'booking_changed',
                'updated_at' => now(),
            ]);

        ScenarioAction::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $actionIds)
            ->update([
                'status' => ScenarioActionStatus::Cancelled->value,
                'suppressed_at' => now(),
                'terminal_reason' => 'booking_changed',
                'updated_at' => now(),
            ]);
    }

    public function cancelForOrganization(int $organizationId): void
    {
        ScenarioAction::query()
            ->where('organization_id', $organizationId)
            ->where('kind', 'appointment_reminder')
            ->whereIn('status', [ScenarioActionStatus::Scheduled, ScenarioActionStatus::Retryable])
            ->pluck('booking_id')
            ->filter()
            ->unique()
            ->each(function (mixed $bookingId) use ($organizationId): void {
                $this->cancelForBooking($organizationId, (int) $bookingId);
            });
    }

    public function rebuildForOrganization(int $organizationId): void
    {
        $this->cancelForOrganization($organizationId);

        Booking::query()
            ->where('organization_id', $organizationId)
            ->where('status', BookingStatus::Confirmed->value)
            ->where('starts_at', '>', now())
            ->orderBy('id')
            ->chunkById(100, function (Collection $bookings) use ($organizationId): void {
                foreach ($bookings as $booking) {
                    $event = ScenarioEvent::query()
                        ->where('organization_id', $organizationId)
                        ->whereIn('event_name', ['booking.confirmed', 'booking.rescheduled'])
                        ->whereJsonContains('payload->booking_id', (int) $booking->getKey())
                        ->latest('id')
                        ->first();
                    if ($event instanceof ScenarioEvent) {
                        $this->schedule($booking, $event);
                    }
                }
            });
    }

    private function recipient(Booking $booking, string $recipientType): ?ScenarioRecipient
    {
        if ($recipientType === 'client') {
            return new ScenarioRecipient(
                type: 'client',
                clientId: (int) $booking->client_id,
                userId: null,
                locale: $this->locale((string) $booking->client->language),
            );
        }

        if ($booking->specialist->staff_user_id === null || ! $booking->specialist->notifications_enabled) {
            return null;
        }

        return new ScenarioRecipient(
            type: 'internal',
            clientId: null,
            userId: (int) $booking->specialist->staff_user_id,
            locale: 'ru',
        );
    }

    private function template(int $organizationId, string $recipientType, string $format, string $locale): ?NotificationTemplateVersion
    {
        $query = NotificationTemplateVersion::query()
            ->where('organization_id', $organizationId)
            ->where('status', NotificationTemplateStatus::Published->value)
            ->whereHas('template', fn ($template) => $template
                ->where('organization_id', $organizationId)
                ->where('template_key', $this->templateKey($recipientType, $format))
                ->where('purpose', 'service')
                ->where('is_active', true))
            ->with('template')
            ->latest('id');

        $template = (clone $query)->whereHas('template', fn ($template) => $template->where('locale', $locale))->first();

        return $template ?? $query->whereHas('template', fn ($template) => $template->where('locale', 'ru'))->first();
    }

    private function templateKey(string $recipientType, string $format): string
    {
        return 'appointment-reminder-'.$recipientType.'-'.$format;
    }

    private function ruleKey(string $recipientType, string $format): string
    {
        return $this->templateKey($recipientType, $format);
    }

    private function locale(string $language): string
    {
        return str_starts_with(strtolower(trim($language)), 'en') ? 'en' : 'ru';
    }

    private function offsetLabel(int $value, ScenarioDelayUnit $unit): string
    {
        return match ($unit) {
            ScenarioDelayUnit::Minutes => $value.' '.($value === 1 ? 'минуту' : 'минут'),
            ScenarioDelayUnit::Hours => $value.' '.($value === 1 ? 'час' : 'часа'),
            ScenarioDelayUnit::Days => $value.' '.($value === 1 ? 'день' : 'дня'),
        };
    }
}
