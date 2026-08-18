<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\PaymentRequirementType;
use App\Modules\Scheduling\Domain\Enums\PaymentStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BookingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Информация о приёме')
                    ->schema([
                        TextEntry::make('client.full_name')->label('Клиент')->wrap(),
                        TextEntry::make('specialist.display_name')->label('Специалист')->wrap(),
                        TextEntry::make('service.name')->label('Услуга')->wrap(),
                        TextEntry::make('visit_format')
                            ->label('Формат')
                            ->formatStateUsing(fn (VisitFormat|string $state): string => self::formatLabel($state)),
                        TextEntry::make('starts_at')->label('Дата и время начала')->dateTime('d.m.Y H:i'),
                        TextEntry::make('ends_at')->label('Окончание')->dateTime('d.m.Y H:i'),
                        TextEntry::make('party_size')->label('Количество персон'),
                        TextEntry::make('requested_at')->label('Заявка создана')->dateTime('d.m.Y H:i'),
                        TextEntry::make('location')->label('Адрес')->placeholder('Не указан')->columnSpanFull()->wrap(),
                        TextEntry::make('meeting_url')
                            ->label('Ссылка на онлайн-встречу')
                            ->placeholder('Не указана')
                            ->url(fn (Booking $record): ?string => $record->meeting_url)
                            ->openUrlInNewTab()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Статус и оплата')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Статус записи')
                            ->badge()
                            ->formatStateUsing(fn (BookingStatus|string $state): string => self::statusLabel($state)),
                        TextEntry::make('payment_status')
                            ->label('Статус оплаты')
                            ->badge()
                            ->formatStateUsing(fn (PaymentStatus|string $state): string => self::paymentStatusLabel($state)),
                        TextEntry::make('payment_requirement')
                            ->label('Условие оплаты')
                            ->formatStateUsing(fn (PaymentRequirementType|string|null $state): string => self::paymentRequirementLabel($state)),
                    ])
                    ->columns(3),

                Section::make('История событий')
                    ->schema([
                        TextEntry::make('history')
                            ->label('Журнал изменений')
                            ->state(function (Booking $record): string {
                                return $record->events()
                                    ->with(['actorUser', 'actorClient'])
                                    ->orderBy('occurred_at')
                                    ->get()
                                    ->map(fn (BookingEvent $event): string => self::formatHistoryEvent($event))
                                    ->implode("\n");
                            })
                            ->placeholder('Событий пока нет')
                            ->columnSpanFull()
                            ->wrap(),
                    ]),
            ]);
    }

    private static function formatHistoryEvent(BookingEvent $event): string
    {
        $oldStart = self::safeValue($event->old_values, 'starts_at');
        $newStart = self::safeValue($event->new_values, 'starts_at');
        $actor = match ($event->actor_type) {
            'user' => $event->actorUser instanceof User ? $event->actorUser->name : 'Сотрудник',
            'client' => $event->actorClient instanceof Client ? $event->actorClient->full_name : 'Клиент',
            default => 'Система',
        };
        $values = [
            self::eventLabel($event),
            $event->occurred_at->format('d.m.Y H:i'),
            'Изменил: '.$actor,
        ];

        if ($event->event_type === BookingEventType::Rescheduled && $oldStart !== null && $newStart !== null) {
            $values[] = 'С '.self::humanDateTime($oldStart).' на '.self::humanDateTime($newStart);
        }

        if ($event->reason !== null) {
            $values[] = 'Причина: '.$event->reason;
        }

        return implode(' · ', $values);
    }

    private static function humanDateTime(string $value): string
    {
        return CarbonImmutable::parse($value)->format('d.m.Y H:i');
    }

    private static function eventLabel(BookingEvent $event): string
    {
        return match ($event->event_type) {
            BookingEventType::Created => 'Запись создана',
            BookingEventType::StatusChanged => 'Статус записи обновлён',
            BookingEventType::Rescheduled => 'Запись перенесена',
            BookingEventType::Cancelled => 'Запись отменена',
            BookingEventType::Completed => 'Визит завершён',
            BookingEventType::NoShow => 'Отмечена неявка',
            BookingEventType::MeetingLinkUpdated => 'Ссылка на встречу обновлена',
        };
    }

    private static function formatLabel(VisitFormat|string $format): string
    {
        $format = $format instanceof VisitFormat ? $format : VisitFormat::tryFrom($format);

        return match ($format) {
            VisitFormat::Office => 'В клинике',
            VisitFormat::HomeVisit => 'Выезд на дом',
            VisitFormat::Online => 'Онлайн',
            default => 'Не указан',
        };
    }

    private static function statusLabel(BookingStatus|string $status): string
    {
        $status = $status instanceof BookingStatus ? $status : BookingStatus::tryFrom($status);

        return match ($status) {
            BookingStatus::Requested => 'Ожидает подтверждения',
            BookingStatus::PendingReview => 'На рассмотрении',
            BookingStatus::Confirmed => 'Подтверждена',
            BookingStatus::Rejected => 'Отклонена',
            BookingStatus::Cancelled => 'Отменена',
            BookingStatus::Completed => 'Завершена',
            BookingStatus::NoShow => 'Не состоялась',
            default => 'Не указан',
        };
    }

    private static function paymentStatusLabel(PaymentStatus|string $status): string
    {
        $status = $status instanceof PaymentStatus ? $status : PaymentStatus::tryFrom($status);

        return match ($status) {
            PaymentStatus::Unpaid => 'Не оплачено',
            PaymentStatus::Pending => 'Ожидается',
            PaymentStatus::PartiallyPaid => 'Оплачено частично',
            PaymentStatus::Paid => 'Оплачено',
            PaymentStatus::Refunded => 'Возвращено',
            default => 'Не указано',
        };
    }

    private static function paymentRequirementLabel(PaymentRequirementType|string|null $requirement): string
    {
        $requirement = $requirement instanceof PaymentRequirementType || $requirement === null
            ? $requirement
            : PaymentRequirementType::tryFrom($requirement);

        return match ($requirement) {
            PaymentRequirementType::FullPayment => 'Полная оплата',
            PaymentRequirementType::TransportDeposit => 'Депозит за выезд',
            default => 'Не указано',
        };
    }

    /** @param array<string, mixed> $values */
    private static function safeValue(array $values, string $key): ?string
    {
        return isset($values[$key]) && is_string($values[$key]) ? $values[$key] : null;
    }
}
