<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\PaymentStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Services\Domain\Models\Service;
use Carbon\CarbonImmutable;

final class ListClientBookings
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly GetBookingCancellationCutoff $cutoff,
    ) {}

    /** @return array{upcoming: list<array<string, mixed>>, history: list<array<string, mixed>>} */
    public function handle(?string $locale = null): array
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
            'upcoming' => array_values($upcoming->map(fn (Booking $booking): array => $this->projection($booking, $locale))->all()),
            'history' => array_values($history->map(fn (Booking $booking): array => $this->projection($booking, $locale))->all()),
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
    public function projection(Booking $booking, ?string $locale = null, ?string $displayTimezone = null): array
    {
        $client = $this->clientContext->client();
        $timezone = $displayTimezone ?? $client->timezone;
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
            'service' => ['name' => $this->localizedServiceName($booking->service, $locale)],
            'specialist' => ['displayName' => $booking->specialist->display_name],
            'startsAt' => $booking->startsAtUtc()->toIso8601String(),
            'endsAt' => $booking->endsAtUtc()->toIso8601String(),
            'localDate' => $localStart->toDateString(),
            'localTime' => $localStart->format('H:i'),
            'localEndsAt' => $localEnd->format('H:i'),
            'displayUtcOffset' => $localStart->format('P'),
            'timezone' => $timezone,
            'formatLabel' => $this->formatLabel($booking->visit_format, $locale),
            'format' => $booking->visit_format->value,
            'statusLabel' => $this->statusLabel($booking->status, $locale),
            'paymentStatusLabel' => $this->paymentStatusLabel($booking->payment_status, $locale),
            'location' => $booking->location,
            'workingLocationId' => $booking->working_location_id,
            'locationArea' => $booking->location_area,
            'locationSnapshot' => $booking->locationSnapshot(),
            'meetingUrl' => $booking->status === BookingStatus::Confirmed
                && $booking->visit_format === VisitFormat::Online
                ? $booking->effectiveMeetingUrl()
                : null,
            'meetingPending' => $booking->status === BookingStatus::Confirmed
                && $booking->visit_format === VisitFormat::Online
                && $booking->meeting_link_mode === MeetingLinkMode::Auto
                && $booking->provider_sync_status === VideoMeetingSyncStatus::Pending,
            'partySize' => $booking->party_size,
            'eventVersion' => $booking->event_version,
            'canCancel' => ! $terminal && ($pendingHomeVisit || $outsideCutoff),
            'canReschedule' => ! $terminal && ($pendingHomeVisit || $outsideCutoff),
            'pendingReview' => $pendingHomeVisit,
            'history' => $this->safeHistory($booking, $locale),
        ];
    }

    /** @return list<array{label: string, oldStartsAt: string|null, newStartsAt: string|null, occurredAt: string}> */
    private function safeHistory(Booking $booking, ?string $locale): array
    {
        if (! $booking->relationLoaded('events')) {
            return [];
        }

        return array_values($booking->events
            ->sortBy('occurred_at')
            ->map(function (BookingEvent $event) use ($locale): array {
                $oldValues = $event->old_values;
                $newValues = $event->new_values;

                return [
                    'label' => $this->historyLabel($event, $locale),
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

    private function formatLabel(VisitFormat $format, ?string $locale): string
    {
        if ($this->isEnglish($locale)) {
            return match ($format) {
                VisitFormat::Office => 'At the clinic',
                VisitFormat::HomeVisit => 'Home visit',
                VisitFormat::Online => 'Online',
            };
        }

        return match ($format) {
            VisitFormat::Office => 'В клинике',
            VisitFormat::HomeVisit => 'Выезд на дом',
            VisitFormat::Online => 'Онлайн',
        };
    }

    private function statusLabel(BookingStatus $status, ?string $locale): string
    {
        if ($this->isEnglish($locale)) {
            return match ($status) {
                BookingStatus::Requested => 'Awaiting confirmation',
                BookingStatus::PendingReview => 'Request sent',
                BookingStatus::Confirmed => 'Confirmed',
                BookingStatus::Rejected => 'Request declined',
                BookingStatus::Cancelled => 'Cancelled',
                BookingStatus::Completed => 'Completed',
                BookingStatus::NoShow => 'Did not attend',
            };
        }

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

    private function paymentStatusLabel(PaymentStatus $status, ?string $locale): string
    {
        if ($this->isEnglish($locale)) {
            return match ($status) {
                PaymentStatus::Unpaid => 'Payment not made',
                PaymentStatus::Pending => 'Payment pending',
                PaymentStatus::PartiallyPaid => 'Partially paid',
                PaymentStatus::Paid => 'Paid',
                PaymentStatus::Refunded => 'Payment refunded',
            };
        }

        return match ($status) {
            PaymentStatus::Unpaid => 'Оплата не внесена',
            PaymentStatus::Pending => 'Оплата ожидается',
            PaymentStatus::PartiallyPaid => 'Оплачено частично',
            PaymentStatus::Paid => 'Оплачено',
            PaymentStatus::Refunded => 'Оплата возвращена',
        };
    }

    private function historyLabel(BookingEvent $event, ?string $locale): string
    {
        if ($this->isEnglish($locale)) {
            return match ($event->event_type) {
                BookingEventType::Created => 'Booking created',
                BookingEventType::Rescheduled => 'Booking rescheduled',
                BookingEventType::Cancelled => 'Booking cancelled',
                BookingEventType::Completed => 'Visit completed',
                BookingEventType::NoShow => 'No-show recorded',
                BookingEventType::MeetingLinkUpdated => 'Meeting link updated',
                BookingEventType::StatusChanged => $this->statusHistoryLabel(
                    $event->new_values['status'] ?? null,
                    $locale,
                ),
            };
        }

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

    private function statusHistoryLabel(mixed $status, ?string $locale = null): string
    {
        $bookingStatus = is_string($status) ? BookingStatus::tryFrom($status) : null;

        if ($this->isEnglish($locale)) {
            return match ($bookingStatus) {
                BookingStatus::Confirmed => 'Booking confirmed',
                BookingStatus::PendingReview => 'Request sent',
                BookingStatus::Rejected => 'Request declined',
                BookingStatus::Cancelled => 'Booking cancelled',
                BookingStatus::Completed => 'Visit completed',
                BookingStatus::NoShow => 'No-show recorded',
                BookingStatus::Requested => 'Booking created',
                default => 'Booking status updated',
            };
        }

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

    private function isEnglish(?string $locale): bool
    {
        return str_starts_with(strtolower((string) $locale), 'en');
    }

    private function localizedServiceName(Service $service, ?string $locale): string
    {
        $primary = $this->isEnglish($locale) ? 'name_en' : 'name_ru';
        $secondary = $this->isEnglish($locale) ? 'name_ru' : 'name_en';

        foreach ([$primary, $secondary, 'name'] as $field) {
            $value = $service->getAttribute($field);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
