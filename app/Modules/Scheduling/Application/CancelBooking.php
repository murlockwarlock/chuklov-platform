<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scenarios\Application\AppointmentReminderScheduler;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scheduling\Domain\Contracts\BookingVideoMeetingLifecycle;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CancelBooking
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BookingAuthorization $authorization,
        private readonly GetBookingCancellationCutoff $cutoff,
        private readonly RecordBookingEvent $events,
        private readonly BookingVideoMeetingLifecycle $videoMeetings,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly AppointmentReminderScheduler $reminders,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User|Client $actor, Booking $booking, ?string $reason = null): Booking
    {
        $organization = $this->context->organization();
        $organizationId = (int) $organization->getKey();

        try {
            $this->authorization->authorize($actor, $booking);
        } catch (AuthorizationException $exception) {
            $this->logFailure($actor, $booking, $organizationId, 'authorization_denied', $exception::class);
            throw $exception;
        }

        $reason = $this->normalizeReason($reason, $actor, $booking, $organizationId);

        try {
            return DB::transaction(function () use ($actor, $booking, $reason, $organization, $organizationId): Booking {
                $lockedBooking = Booking::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($booking->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (in_array($lockedBooking->status->value, BookingStatus::terminalValues(), true)) {
                    $this->reject(
                        $actor,
                        $lockedBooking,
                        $organizationId,
                        'booking',
                        'This booking is already terminal.',
                        'booking_already_terminal',
                    );
                }

                $pendingHomeVisit = $lockedBooking->status === BookingStatus::PendingReview
                    && $lockedBooking->visit_format === VisitFormat::HomeVisit;

                if (! $pendingHomeVisit && $lockedBooking->status !== BookingStatus::Requested
                    && $lockedBooking->status !== BookingStatus::Confirmed) {
                    $this->reject(
                        $actor,
                        $lockedBooking,
                        $organizationId,
                        'booking',
                        'This booking cannot be cancelled.',
                        'booking_cannot_be_cancelled',
                    );
                }

                if ($actor instanceof Client && ! $pendingHomeVisit && ! $this->isOutsideCutoff($lockedBooking)) {
                    $this->reject(
                        $actor,
                        $lockedBooking,
                        $organizationId,
                        'booking',
                        'Self-service cancellation is closed. Please contact staff.',
                        'self_service_cancellation_closed',
                    );
                }

                if ($actor instanceof User && ! $pendingHomeVisit && ! $this->isOutsideCutoff($lockedBooking)
                    && $reason === null) {
                    $this->reject(
                        $actor,
                        $lockedBooking,
                        $organizationId,
                        'reason',
                        'A reason is required when cancelling inside the configured cutoff.',
                        'cancellation_reason_required',
                    );
                }

                $oldValues = $this->events->snapshot($lockedBooking);
                $lockedBooking->forceFill([
                    'status' => BookingStatus::Cancelled,
                    'cancelled_at' => now(),
                    'event_version' => $lockedBooking->event_version + 1,
                ])->save();
                $bookingEvent = $this->events->handle(
                    booking: $lockedBooking,
                    actor: $actor,
                    type: BookingEventType::Cancelled,
                    oldValues: $oldValues,
                    newValues: $this->events->snapshot($lockedBooking),
                    reason: $reason,
                );
                $this->scenarioEvents->bookingCancelled(
                    booking: $lockedBooking,
                    causationId: (string) $bookingEvent->getKey(),
                    occurredAt: CarbonImmutable::instance($bookingEvent->occurred_at),
                );
                $this->reminders->cancelForBooking((int) $organization->getKey(), (int) $lockedBooking->getKey());
                if ($lockedBooking->visit_format === VisitFormat::Online
                    && $lockedBooking->meeting_link_mode === MeetingLinkMode::Auto) {
                    $this->videoMeetings->scheduleCancel($organization, $lockedBooking);
                }
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor instanceof User ? $actor : null,
                    action: $pendingHomeVisit ? 'booking.home_visit.withdrawn' : 'booking.cancelled',
                    targetType: Booking::class,
                    targetId: (string) $lockedBooking->getKey(),
                    metadata: [
                        'source' => $actor instanceof User ? 'crm' : 'portal',
                        'status' => BookingStatus::Cancelled->value,
                        'inside_cutoff' => ! $pendingHomeVisit && ! $this->isOutsideCutoff($lockedBooking),
                    ],
                );

                return $lockedBooking->refresh();
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure($actor, $booking, $organizationId, 'cancellation_processing_failed', $exception::class);
            throw $exception;
        }
    }

    private function isOutsideCutoff(Booking $booking): bool
    {
        return $booking->startsAtUtc()->greaterThanOrEqualTo(
            CarbonImmutable::instance(now())->utc()->addMinutes($this->cutoff->handle()),
        );
    }

    private function normalizeReason(
        ?string $reason,
        User|Client $actor,
        Booking $booking,
        int $organizationId,
    ): ?string {
        $reason = $reason === null ? null : trim($reason);

        if ($reason !== null && ($reason === '' || mb_strlen($reason) > 500)) {
            $this->reject(
                $actor,
                $booking,
                $organizationId,
                'reason',
                'The cancellation reason is invalid.',
                'invalid_cancellation_reason',
            );
        }

        return $reason;
    }

    private function reject(
        User|Client $actor,
        Booking $booking,
        int $organizationId,
        string $field,
        string $message,
        string $errorCode,
    ): never {
        $this->logFailure($actor, $booking, $organizationId, $errorCode, ValidationException::class);

        throw ValidationException::withMessages([$field => $message]);
    }

    private function logFailure(
        User|Client $actor,
        Booking $booking,
        int $organizationId,
        string $errorCode,
        string $errorClass,
    ): void {
        Log::warning('booking.cancel_failed', [
            'event_name' => 'booking.cancel_failed',
            'operation' => 'booking.cancel',
            'organization_id' => $organizationId,
            'actor_type' => $actor instanceof Client ? 'client' : 'user',
            'actor_id' => (int) $actor->getKey(),
            'entity_type' => 'booking',
            'entity_id' => (int) $booking->getKey(),
            'entity_organization_id' => (int) $booking->organization_id,
            'client_id' => (int) $booking->client_id,
            'specialist_id' => (int) $booking->specialist_id,
            'service_id' => (int) $booking->service_id,
            'error_code' => $errorCode,
            'error_class' => $errorClass,
            'outcome' => 'rejected',
            'occurred_at' => now()->utc()->toIso8601String(),
        ]);
    }
}
