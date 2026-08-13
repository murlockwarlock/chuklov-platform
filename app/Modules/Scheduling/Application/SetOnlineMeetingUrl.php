<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetOnlineMeetingUrl
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BookingAuthorization $authorization,
        private readonly RecordBookingEvent $events,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Booking $booking, string $meetingUrl, ?string $reason = null): Booking
    {
        $this->authorization->authorize($actor, $booking);
        $meetingUrl = trim($meetingUrl);
        $reason = $reason === null ? null : trim($reason);

        if (filter_var($meetingUrl, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($meetingUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
            || mb_strlen($meetingUrl) > 2000) {
            throw ValidationException::withMessages(['meetingUrl' => 'The meeting URL must be a valid HTTP or HTTPS URL.']);
        }

        if ($reason !== null && ($reason === '' || mb_strlen($reason) > 500)) {
            throw ValidationException::withMessages(['reason' => 'The meeting-link reason is invalid.']);
        }

        $organization = $this->context->organization();

        return DB::transaction(function () use ($actor, $booking, $meetingUrl, $reason, $organization): Booking {
            $lockedBooking = Booking::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->visit_format !== VisitFormat::Online
                || $lockedBooking->meeting_link_mode !== MeetingLinkMode::Manual
                || ! in_array($lockedBooking->status, [BookingStatus::Requested, BookingStatus::Confirmed], true)) {
                throw ValidationException::withMessages(['booking' => 'Only requested or confirmed manual online bookings can receive a meeting URL.']);
            }

            $oldValues = [...$this->events->snapshot($lockedBooking), 'meeting_url_set' => $lockedBooking->meeting_url !== null];
            $lockedBooking->forceFill([
                'meeting_url' => $meetingUrl,
                'event_version' => $lockedBooking->event_version + 1,
            ])->save();
            $newValues = [...$this->events->snapshot($lockedBooking), 'meeting_url_set' => true];
            $this->events->handle(
                booking: $lockedBooking,
                actor: $actor,
                type: BookingEventType::MeetingLinkUpdated,
                oldValues: $oldValues,
                newValues: $newValues,
                reason: $reason,
            );
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'booking.online.meeting_url.updated',
                targetType: Booking::class,
                targetId: (string) $lockedBooking->getKey(),
                metadata: [
                    'source' => 'crm',
                    'status' => $lockedBooking->status->value,
                    'visit_format' => VisitFormat::Online->value,
                    'url_set' => true,
                ],
            );

            return $lockedBooking->refresh();
        });
    }
}
