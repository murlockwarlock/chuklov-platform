<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateBookingPartySize
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BookingAuthorization $authorization,
        private readonly RecordBookingEvent $events,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        Booking $booking,
        int $partySize,
        ?int $expectedEventVersion = null,
    ): Booking {
        $this->authorization->authorize($actor, $booking);
        $organization = $this->context->organization();
        $maxPartySize = (int) config('scheduling.max_party_size', 20);

        if ($partySize < 1 || $partySize > $maxPartySize) {
            throw ValidationException::withMessages([
                'partySize' => __('Количество человек должно быть от 1 до :max.', ['max' => $maxPartySize]),
            ]);
        }

        return DB::transaction(function () use ($actor, $booking, $partySize, $expectedEventVersion, $organization): Booking {
            $lockedBooking = Booking::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($expectedEventVersion === null || $lockedBooking->event_version !== $expectedEventVersion) {
                throw ValidationException::withMessages([
                    'expected_event_version' => 'Эта запись уже изменилась. Обновите страницу и попробуйте ещё раз.',
                ]);
            }

            if (in_array($lockedBooking->status->value, BookingStatus::terminalValues(), true)) {
                throw ValidationException::withMessages([
                    'booking' => 'Для завершённой записи нельзя изменить количество человек.',
                ]);
            }

            if ($lockedBooking->party_size === $partySize) {
                return $lockedBooking;
            }

            $oldValues = $this->events->snapshot($lockedBooking);
            $lockedBooking->forceFill([
                'party_size' => $partySize,
                'event_version' => $lockedBooking->event_version + 1,
            ])->save();

            $this->events->handle(
                booking: $lockedBooking,
                actor: $actor,
                type: BookingEventType::PartySizeUpdated,
                oldValues: $oldValues,
                newValues: $this->events->snapshot($lockedBooking),
            );
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'booking.party_size.updated',
                targetType: Booking::class,
                targetId: (string) $lockedBooking->getKey(),
                metadata: [
                    'source' => 'crm',
                    'status' => $lockedBooking->status->value,
                    'visit_format' => $lockedBooking->visit_format->value,
                ],
            );

            return $lockedBooking->refresh();
        });
    }
}
