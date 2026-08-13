<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use Carbon\CarbonImmutable;

final class ListClientBookings
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly GetBookingCancellationCutoff $cutoff,
    ) {}

    /** @return array{upcoming: list<array<string, mixed>>, history: list<array<string, mixed>>} */
    public function handle(): array
    {
        $client = $this->clientContext->client();
        $bookings = Booking::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->with(['service', 'specialist'])
            ->orderBy('starts_at')
            ->get();
        $now = CarbonImmutable::instance(now())->utc();

        $upcoming = $bookings->filter(
            fn (Booking $booking): bool => $booking->startsAtUtc()->greaterThanOrEqualTo($now)
                && ! in_array($booking->status->value, BookingStatus::terminalValues(), true),
        );
        $history = $bookings->reject(
            fn (Booking $booking): bool => $upcoming->contains('id', $booking->getKey()),
        )->sortByDesc('starts_at');

        return [
            'upcoming' => array_values($upcoming->map(fn (Booking $booking): array => $this->projection($booking))->all()),
            'history' => array_values($history->map(fn (Booking $booking): array => $this->projection($booking))->all()),
        ];
    }

    public function find(int $bookingId): ?Booking
    {
        $client = $this->clientContext->client();

        return Booking::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->whereKey($bookingId)
            ->with(['service', 'specialist', 'events'])
            ->first();
    }

    /** @return array<string, mixed> */
    public function projection(Booking $booking): array
    {
        $client = $this->clientContext->client();
        $timezone = $booking->client_timezone ?? $client->timezone;
        $localStart = $booking->startsAtUtc()->setTimezone($timezone);
        $localEnd = $booking->endsAtUtc()->setTimezone($timezone);
        $pendingHomeVisit = $booking->status->value === BookingStatus::PendingReview->value
            && $booking->visit_format === VisitFormat::HomeVisit;
        $terminal = in_array($booking->status->value, BookingStatus::terminalValues(), true);
        $outsideCutoff = $booking->startsAtUtc()->greaterThanOrEqualTo(
            CarbonImmutable::instance(now())->utc()->addMinutes($this->cutoff->handle()),
        );

        return [
            'id' => $booking->getKey(),
            'service' => ['name' => $booking->service->name],
            'specialist' => ['displayName' => $booking->specialist->display_name],
            'startsAt' => $booking->startsAtUtc()->toIso8601String(),
            'endsAt' => $booking->endsAtUtc()->toIso8601String(),
            'localDate' => $localStart->toDateString(),
            'localTime' => $localStart->format('H:i'),
            'localEndsAt' => $localEnd->format('H:i'),
            'timezone' => $timezone,
            'scheduleTimezone' => $booking->schedule_timezone,
            'format' => $booking->visit_format->value,
            'formatLabel' => $this->formatLabel($booking->visit_format),
            'status' => $booking->status->value,
            'statusLabel' => $this->statusLabel($booking->status),
            'paymentStatus' => $booking->payment_status->value,
            'location' => $booking->location,
            'meetingUrl' => $booking->visit_format === VisitFormat::Online ? $booking->meeting_url : null,
            'partySize' => $booking->party_size,
            'calendarUid' => $booking->calendar_uid,
            'eventVersion' => $booking->event_version,
            'canCancel' => ! $terminal && ($pendingHomeVisit || $outsideCutoff),
            'canReschedule' => ! $terminal && ($pendingHomeVisit || $outsideCutoff),
            'contactStaff' => ! $terminal && ! $pendingHomeVisit && ! $outsideCutoff,
            'pendingReview' => $pendingHomeVisit,
            'history' => $this->safeHistory($booking),
        ];
    }

    /** @return list<array{eventType: string, status: string|null, startsAt: string|null, occurredAt: string}> */
    private function safeHistory(Booking $booking): array
    {
        if (! $booking->relationLoaded('events')) {
            return [];
        }

        return array_values($booking->events
            ->sortBy('occurred_at')
            ->map(function (BookingEvent $event): array {
                $newValues = $event->new_values;

                return [
                    'eventType' => $event->event_type->value,
                    'status' => isset($newValues['status']) && is_string($newValues['status']) ? $newValues['status'] : null,
                    'startsAt' => isset($newValues['starts_at']) && is_string($newValues['starts_at']) ? $newValues['starts_at'] : null,
                    'occurredAt' => $event->occurred_at->toIso8601String(),
                ];
            })->all());
    }

    private function formatLabel(VisitFormat $format): string
    {
        return match ($format) {
            VisitFormat::Office => 'Office',
            VisitFormat::HomeVisit => 'Home visit',
            VisitFormat::Online => 'Online',
        };
    }

    private function statusLabel(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Requested => 'Requested',
            BookingStatus::PendingReview => 'Awaiting review',
            BookingStatus::Confirmed => 'Confirmed',
            BookingStatus::Rejected => 'Request declined',
            BookingStatus::Cancelled => 'Cancelled',
            BookingStatus::Completed => 'Completed',
            BookingStatus::NoShow => 'No-show',
        };
    }
}
