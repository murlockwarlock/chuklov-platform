<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MarkBookingNoShow
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BookingAuthorization $authorization,
        private readonly RecordBookingEvent $events,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Booking $booking, ?string $reason = null): Booking
    {
        $this->authorization->authorize($actor, $booking);
        $reason = $this->reason($reason);
        $organization = $this->context->organization();

        return DB::transaction(function () use ($actor, $booking, $reason, $organization): Booking {
            $lockedBooking = Booking::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedBooking->status, [BookingStatus::Requested, BookingStatus::Confirmed], true)) {
                throw ValidationException::withMessages(['booking' => 'Неявку можно отметить только для записей, ожидающих подтверждения или подтверждённых.']);
            }

            if (CarbonImmutable::instance(now())->utc()->lessThan($lockedBooking->startsAtUtc())) {
                throw ValidationException::withMessages(['booking' => 'Неявку можно отметить только после наступления времени начала записи.']);
            }

            $oldValues = $this->events->snapshot($lockedBooking);
            $lockedBooking->forceFill([
                'status' => BookingStatus::NoShow,
                'event_version' => $lockedBooking->event_version + 1,
            ])->save();
            $this->events->handle(
                booking: $lockedBooking,
                actor: $actor,
                type: BookingEventType::NoShow,
                oldValues: $oldValues,
                newValues: $this->events->snapshot($lockedBooking),
                reason: $reason,
            );
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'booking.no_show',
                targetType: Booking::class,
                targetId: (string) $lockedBooking->getKey(),
                metadata: [
                    'source' => 'crm',
                    'status' => BookingStatus::NoShow->value,
                    'visit_format' => $lockedBooking->visit_format->value,
                ],
            );

            return $lockedBooking->refresh();
        });
    }

    private function reason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        if ($reason !== null && ($reason === '' || mb_strlen($reason) > 500)) {
            throw ValidationException::withMessages(['reason' => 'The no-show reason is invalid.']);
        }

        return $reason;
    }
}
