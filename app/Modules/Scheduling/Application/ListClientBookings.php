<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\PaymentStatus;
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
        $timezone = $client->timezone;
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
            'formatLabel' => $this->formatLabel($booking->visit_format),
            'statusLabel' => $this->statusLabel($booking->status),
            'paymentStatusLabel' => $this->paymentStatusLabel($booking->payment_status),
            'location' => $booking->location,
            'meetingUrl' => $booking->visit_format === VisitFormat::Online ? $booking->meeting_url : null,
            'partySize' => $booking->party_size,
            'eventVersion' => $booking->event_version,
            'canCancel' => ! $terminal && ($pendingHomeVisit || $outsideCutoff),
            'canReschedule' => ! $terminal && ($pendingHomeVisit || $outsideCutoff),
            'contactStaff' => ! $terminal && ! $pendingHomeVisit && ! $outsideCutoff,
            'pendingReview' => $pendingHomeVisit,
            'history' => $this->safeHistory($booking),
        ];
    }

    /** @return list<array{label: string, oldStartsAt: string|null, newStartsAt: string|null, occurredAt: string}> */
    private function safeHistory(Booking $booking): array
    {
        if (! $booking->relationLoaded('events')) {
            return [];
        }

        return array_values($booking->events
            ->sortBy('occurred_at')
            ->map(function (BookingEvent $event): array {
                $oldValues = $event->old_values;
                $newValues = $event->new_values;

                return [
                    'label' => $this->historyLabel($event),
                    'oldStartsAt' => $event->event_type === BookingEventType::Rescheduled
                        && isset($oldValues['starts_at']) && is_string($oldValues['starts_at'])
                        ? $oldValues['starts_at']
                        : null,
                    'newStartsAt' => $event->event_type === BookingEventType::Rescheduled
                        && isset($newValues['starts_at']) && is_string($newValues['starts_at'])
                        ? $newValues['starts_at']
                        : null,
                    'occurredAt' => $event->occurred_at->toIso8601String(),
                ];
            })->all());
    }

    private function formatLabel(VisitFormat $format): string
    {
        return match ($format) {
            VisitFormat::Office => 'В клинике',
            VisitFormat::HomeVisit => 'Выезд на дом',
            VisitFormat::Online => 'Онлайн',
        };
    }

    private function statusLabel(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Requested => 'Ожидает подтверждения',
            BookingStatus::PendingReview => 'Заявка отправлена',
            BookingStatus::Confirmed => 'Подтверждена',
            BookingStatus::Rejected => 'Заявка отклонена',
            BookingStatus::Cancelled => 'Отменена',
            BookingStatus::Completed => 'Завершена',
            BookingStatus::NoShow => 'Не состоялась',
        };
    }

    private function paymentStatusLabel(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Unpaid => 'Оплата не внесена',
            PaymentStatus::Pending => 'Оплата ожидается',
            PaymentStatus::PartiallyPaid => 'Оплачено частично',
            PaymentStatus::Paid => 'Оплачено',
            PaymentStatus::Refunded => 'Оплата возвращена',
        };
    }

    private function historyLabel(BookingEvent $event): string
    {
        return match ($event->event_type) {
            BookingEventType::Created => 'Запись создана',
            BookingEventType::Rescheduled => 'Запись перенесена',
            BookingEventType::Cancelled => 'Запись отменена',
            BookingEventType::Completed => 'Визит завершён',
            BookingEventType::NoShow => 'Неявка отмечена',
            BookingEventType::MeetingLinkUpdated => 'Ссылка на встречу обновлена',
            BookingEventType::StatusChanged => $this->statusHistoryLabel($event->new_values['status'] ?? null),
        };
    }

    private function statusHistoryLabel(mixed $status): string
    {
        $bookingStatus = is_string($status) ? BookingStatus::tryFrom($status) : null;

        return match ($bookingStatus) {
            BookingStatus::Confirmed => 'Запись подтверждена',
            BookingStatus::PendingReview => 'Заявка отправлена',
            BookingStatus::Rejected => 'Заявка отклонена',
            BookingStatus::Cancelled => 'Запись отменена',
            BookingStatus::Completed => 'Визит завершён',
            BookingStatus::NoShow => 'Неявка отмечена',
            BookingStatus::Requested => 'Запись создана',
            default => 'Статус записи обновлён',
        };
    }
}
