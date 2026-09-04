<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Modules\Scenarios\Application\AppointmentReminderScheduler;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\PaymentRequirementType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Scheduling\Domain\ValueObjects\AvailabilitySlot;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveHomeVisitBooking
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly CalculateAvailability $availability,
        private readonly SpecialistServiceAssignmentEligibility $eligibility,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly AppointmentReminderScheduler $reminders,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        Booking $booking,
        ?string $reason = null,
        PaymentRequirementType|string|null $paymentRequirement = null,
    ): Booking {
        $organization = $this->context->organization();

        if ((int) $booking->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The booking is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $reason = $this->normalizeReason($reason, required: false);
        $paymentRequirement = $this->paymentRequirement($paymentRequirement);

        return DB::transaction(function () use ($actor, $booking, $organization, $reason, $paymentRequirement): Booking {
            $lockedBooking = Booking::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->status !== BookingStatus::PendingReview
                || $lockedBooking->visit_format !== VisitFormat::HomeVisit) {
                throw ValidationException::withMessages([
                    'booking' => 'Only pending home-visit requests can be approved.',
                ]);
            }

            $specialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($lockedBooking->specialist_id)
                ->lockForUpdate()
                ->firstOrFail();
            $service = Service::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($lockedBooking->service_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->eligibility->ensure(
                $organization->getKey(),
                $specialist->getKey(),
                $service->getKey(),
            );

            $availability = $this->availability->forBooking(
                specialist: $specialist,
                service: $service,
                format: VisitFormat::HomeVisit,
                startsAt: $lockedBooking->startsAtUtc(),
                displayTimezone: $lockedBooking->client_timezone,
                workingLocationId: $lockedBooking->working_location_id,
                locationArea: $lockedBooking->location_area,
            );
            $slot = $this->matchingSlot($availability->slots, $lockedBooking->startsAtUtc());

            if (! $slot instanceof AvailabilitySlot) {
                throw ValidationException::withMessages([
                    'booking' => 'The preferred time is no longer available. Choose another time before approval.',
                ]);
            }

            $oldValues = $this->bookingSnapshot($lockedBooking);
            [$requirementAmount, $requirementCurrency] = $this->paymentRequirementValues($organization->getKey(), $paymentRequirement);
            $lockedBooking->forceFill([
                'status' => BookingStatus::Confirmed,
                'starts_at' => $slot->startsAt,
                'ends_at' => $slot->endsAt,
                'blocking_ends_at' => $slot->blockingEndsAt,
                'schedule_timezone' => $slot->scheduleTimezone,
                'payment_requirement' => $paymentRequirement,
                'payment_requirement_amount_minor' => $requirementAmount,
                'payment_requirement_currency' => $requirementCurrency,
                'event_version' => $lockedBooking->event_version + 1,
            ]);

            try {
                $lockedBooking->save();
            } catch (QueryException $exception) {
                if ($this->isBookingConflict($exception)) {
                    throw ValidationException::withMessages([
                        'booking' => 'The preferred time is no longer available. Choose another time before approval.',
                    ]);
                }

                throw $exception;
            }

            $bookingEvent = $this->recordEvent(
                booking: $lockedBooking,
                actor: $actor,
                oldValues: $oldValues,
                reason: $reason,
            );
            $scenarioEvent = $this->scenarioEvents->bookingConfirmed(
                booking: $lockedBooking,
                causationId: (string) $bookingEvent->getKey(),
                occurredAt: CarbonImmutable::instance($bookingEvent->occurred_at),
            );
            $this->reminders->schedule($lockedBooking, $scenarioEvent);
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'booking.home_visit.approved',
                targetType: Booking::class,
                targetId: (string) $lockedBooking->getKey(),
                metadata: [
                    'source' => 'crm',
                    'status' => BookingStatus::Confirmed->value,
                    'visit_format' => VisitFormat::HomeVisit->value,
                    'payment_requirement' => $paymentRequirement?->value,
                ],
            );

            return $lockedBooking->refresh();
        });
    }

    /** @param list<AvailabilitySlot> $slots */
    private function matchingSlot(array $slots, CarbonImmutable $requestedStart): ?AvailabilitySlot
    {
        foreach ($slots as $slot) {
            if ($slot->startsAt->equalTo($requestedStart)) {
                return $slot;
            }
        }

        return null;
    }

    private function normalizeReason(?string $reason, bool $required): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        if (($required && ($reason === null || $reason === '')) || ($reason !== null && mb_strlen($reason) > 500)) {
            throw ValidationException::withMessages(['reason' => 'The booking reason is invalid.']);
        }

        return $reason === '' ? null : $reason;
    }

    /** @return array<string, mixed> */
    private function bookingSnapshot(Booking $booking): array
    {
        return [
            'status' => $booking->status->value,
            'payment_status' => $booking->payment_status->value,
            'payment_requirement' => $booking->payment_requirement?->value,
            'payment_requirement_amount_minor' => $booking->payment_requirement_amount_minor,
            'payment_requirement_currency' => $booking->payment_requirement_currency,
            'visit_format' => $booking->visit_format->value,
            'starts_at' => $booking->startsAtUtc()->toIso8601String(),
            'ends_at' => $booking->endsAtUtc()->toIso8601String(),
            'blocking_ends_at' => $booking->blockingEndsAtUtc()->toIso8601String(),
            'schedule_timezone' => $booking->schedule_timezone,
            'location' => $booking->location,
            'working_location_id' => $booking->working_location_id,
            'location_area' => $booking->location_area,
            'location_snapshot' => $booking->locationSnapshot(),
            'event_version' => $booking->event_version,
        ];
    }

    /** @param array<string, int|string|null> $oldValues */
    private function recordEvent(Booking $booking, User $actor, array $oldValues, ?string $reason): BookingEvent
    {
        $event = new BookingEvent;
        $event->forceFill([
            'organization_id' => $booking->organization_id,
            'booking_id' => $booking->getKey(),
            'event_type' => BookingEventType::StatusChanged,
            'actor_type' => 'user',
            'actor_user_id' => $actor->getKey(),
            'old_values' => $oldValues,
            'new_values' => $this->bookingSnapshot($booking),
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
        $event->save();

        return $event->refresh();
    }

    private function isBookingConflict(QueryException $exception): bool
    {
        $sqlState = $exception->getCode() ?: ($exception->errorInfo[0] ?? null);

        return in_array($sqlState, ['23P01', '40P01'], true);
    }

    private function paymentRequirement(PaymentRequirementType|string|null $value): ?PaymentRequirementType
    {
        if ($value === null || $value instanceof PaymentRequirementType) {
            return $value;
        }

        $requirement = PaymentRequirementType::tryFrom($value);

        if (! $requirement instanceof PaymentRequirementType) {
            throw ValidationException::withMessages(['paymentRequirement' => 'The payment requirement is invalid.']);
        }

        return $requirement;
    }

    /** @return array{0: int|null, 1: string|null} */
    private function paymentRequirementValues(int $organizationId, ?PaymentRequirementType $requirement): array
    {
        if ($requirement !== PaymentRequirementType::TransportDeposit) {
            return [null, null];
        }

        $settings = OrganizationSetting::query()
            ->where('organization_id', $organizationId)
            ->whereIn('setting_key', [
                OrganizationSettingKey::HomeVisitTransportDepositAmountMinor->value,
                OrganizationSettingKey::HomeVisitTransportDepositCurrency->value,
            ])
            ->pluck('integer_value', 'setting_key');
        $currency = OrganizationSetting::query()
            ->where('organization_id', $organizationId)
            ->where('setting_key', OrganizationSettingKey::HomeVisitTransportDepositCurrency->value)
            ->value('string_value');
        $amount = $settings->get(OrganizationSettingKey::HomeVisitTransportDepositAmountMinor->value);

        if ($amount === null || $currency === null) {
            throw ValidationException::withMessages([
                'paymentRequirement' => 'Transport-deposit amount and currency must be configured before approval.',
            ]);
        }

        return [(int) $amount, (string) $currency];
    }
}
