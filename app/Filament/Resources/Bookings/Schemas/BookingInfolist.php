<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\CrmLabel;
use App\Filament\Support\FinancePresentation;
use App\Models\User;
use App\Modules\Finance\Application\BookingFinanceSummary;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\ResolveSpecialistViewerTimezone;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\PaymentRequirementType;
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
                Section::make(__('Информация о приёме'))
                    ->schema([
                        TextEntry::make('client.full_name')
                            ->label(__('Клиент'))
                            ->url(fn (Booking $record): ?string => CrmEntityLinks::clientUrl($record->client))
                            ->color(fn (Booking $record): ?string => CrmEntityLinks::clientUrl($record->client) === null ? null : 'primary')
                            ->wrap(),
                        TextEntry::make('specialist.display_name')
                            ->label(__('Специалист'))
                            ->url(fn (Booking $record): ?string => CrmEntityLinks::specialistUrl($record->specialist))
                            ->color(fn (Booking $record): ?string => CrmEntityLinks::specialistUrl($record->specialist) === null ? null : 'primary')
                            ->wrap(),
                        TextEntry::make('service.name')->label(__('Услуга'))->wrap(),
                        TextEntry::make('visit_format')
                            ->label(__('Формат'))
                            ->formatStateUsing(fn (VisitFormat|string $state): string => self::formatLabel($state)),
                        TextEntry::make('starts_at')
                            ->label(__('Дата и время начала'))
                            ->dateTime('d.m.Y H:i')
                            ->timezone(fn (): string => self::viewerTimezone()),
                        TextEntry::make('ends_at')
                            ->label(__('Окончание'))
                            ->dateTime('d.m.Y H:i')
                            ->timezone(fn (): string => self::viewerTimezone()),
                        TextEntry::make('schedule_timezone')->label(__('Часовой пояс записи')),
                        TextEntry::make('party_size')
                            ->label(__('Участники выезда'))
                            ->visible(fn (Booking $record): bool => $record->visit_format === VisitFormat::HomeVisit),
                        TextEntry::make('requested_at')
                            ->label(__('Заявка создана'))
                            ->dateTime('d.m.Y H:i')
                            ->timezone(fn (): string => self::viewerTimezone()),
                        TextEntry::make('location')->label(__('Место проведения'))->state(fn (Booking $record): string => self::locationLabel($record))->placeholder(__('Не указано'))->columnSpanFull()->wrap(),
                        TextEntry::make('meeting_url')
                            ->label(__('Ссылка на онлайн-встречу'))
                            ->placeholder(__('Не указана'))
                            ->url(fn (Booking $record): ?string => $record->meeting_url)
                            ->openUrlInNewTab()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Статус и оплата'))
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('Статус записи'))
                            ->badge()
                            ->color(fn (BookingStatus|string $state): string => match ($state instanceof BookingStatus ? $state : BookingStatus::tryFrom($state)) {
                                BookingStatus::Confirmed => 'success',
                                BookingStatus::Requested, BookingStatus::PendingReview => 'warning',
                                BookingStatus::Cancelled, BookingStatus::Rejected, BookingStatus::NoShow => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (BookingStatus|string $state): string => self::statusLabel($state))
                            ->wrap()
                            ->extraAttributes(['class' => 'min-w-0 max-w-full leading-normal whitespace-normal break-words']),
                        TextEntry::make('payment_requirement')
                            ->label(__('Условие оплаты'))
                            ->formatStateUsing(fn (PaymentRequirementType|string|null $state): string => self::paymentRequirementLabel($state))
                            ->wrap(),
                        Section::make(__('История событий'))
                            ->schema([
                                TextEntry::make('history')
                                    ->label(__('Журнал изменений'))
                                    ->state(function (Booking $record): string {
                                        return $record->events()
                                            ->with(['actorUser', 'actorClient'])
                                            ->orderBy('occurred_at')
                                            ->get()
                                            ->map(fn (BookingEvent $event): string => self::formatHistoryEvent($event))
                                            ->implode("\n");
                                    })
                                    ->placeholder(__('Событий пока нет'))
                                    ->columnSpanFull()
                                    ->wrap(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(['default' => 1, 'sm' => 2]),

                Section::make(__('Оплата пока недоступна'))
                    ->visible(fn (Booking $record): bool => app(FinancePresentation::class)->bookingPaymentReadiness($record) !== null)
                    ->schema([
                        TextEntry::make('finance_readiness')
                            ->label(__('Что нужно сделать'))
                            ->state(fn (Booking $record): ?string => app(FinancePresentation::class)->bookingPaymentReadiness($record))
                            ->wrap()
                            ->columnSpanFull(),
                    ]),

                Section::make(__('Расчёт'))
                    ->visible(fn (Booking $record): bool => app(FinancePresentation::class)->bookingSummary($record) !== null)
                    ->schema([
                        TextEntry::make('finance_amount')
                            ->label(__('Сумма'))
                            ->state(fn (Booking $record): string => self::bookingSummary($record) === null
                                ? '—'
                                : app(FinancePresentation::class)->bookingAmount(self::bookingSummary($record))),
                        TextEntry::make('finance_paid')
                            ->label(__('Оплачено'))
                            ->state(fn (Booking $record): string => self::bookingSummary($record) === null
                                ? '—'
                                : app(FinancePresentation::class)->bookingPaid(self::bookingSummary($record))),
                        TextEntry::make('finance_outstanding')
                            ->label(__('Осталось'))
                            ->state(fn (Booking $record): string => self::bookingSummary($record) === null
                                ? '—'
                                : app(FinancePresentation::class)->bookingOutstanding(self::bookingSummary($record))),
                        TextEntry::make('finance_status')
                            ->label(__('Статус'))
                            ->badge()
                            ->state(fn (Booking $record): string => self::bookingSummary($record) === null
                                ? '—'
                                : app(FinancePresentation::class)->bookingStatus(self::bookingSummary($record)))
                            ->color(fn (Booking $record): string => self::bookingSummary($record) === null
                                ? 'gray'
                                : app(FinancePresentation::class)->bookingStatusColor(self::bookingSummary($record))),
                        TextEntry::make('finance_error')
                            ->label(__('Состояние расчёта'))
                            ->state(__('Расчёт недоступен. Проверьте историю оплат.'))
                            ->color('danger')
                            ->visible(fn (Booking $record): bool => self::bookingSummary($record)?->reconciliation === null)
                            ->columnSpanFull(),
                    ])
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4]),

            ]);
    }

    private static function formatHistoryEvent(BookingEvent $event): string
    {
        $oldStart = self::safeValue($event->old_values, 'starts_at');
        $newStart = self::safeValue($event->new_values, 'starts_at');
        $actor = match ($event->actor_type) {
            'user' => $event->actorUser instanceof User ? $event->actorUser->name : __('Сотрудник'),
            'client' => $event->actorClient instanceof Client ? $event->actorClient->full_name : __('Клиент'),
            default => __('Система'),
        };
        $values = [
            self::eventLabel($event),
            $event->occurred_at->copy()
                ->setTimezone(self::viewerTimezone())
                ->format('d.m.Y H:i'),
            __('Изменил: :actor', ['actor' => $actor]),
        ];

        if ($event->event_type === BookingEventType::Rescheduled && $oldStart !== null && $newStart !== null) {
            $values[] = __('С :old на :new', [
                'old' => self::humanDateTime($oldStart),
                'new' => self::humanDateTime($newStart),
            ]);
        }

        if ($event->reason !== null) {
            $values[] = __('Причина: :reason', ['reason' => $event->reason]);
        }

        return implode(' · ', $values);
    }

    private static function humanDateTime(string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')
            ->setTimezone(self::viewerTimezone())
            ->format('d.m.Y H:i');
    }

    private static function eventLabel(BookingEvent $event): string
    {
        return match ($event->event_type) {
            BookingEventType::Created => __('Запись создана'),
            BookingEventType::StatusChanged => __('Статус записи обновлён'),
            BookingEventType::Rescheduled => __('Запись перенесена'),
            BookingEventType::Cancelled => __('Запись отменена'),
            BookingEventType::Completed => __('Визит завершён'),
            BookingEventType::NoShow => __('Отмечена неявка'),
            BookingEventType::MeetingLinkUpdated => __('Ссылка на встречу обновлена'),
        };
    }

    private static function formatLabel(VisitFormat|string $format): string
    {
        $format = $format instanceof VisitFormat ? $format : VisitFormat::tryFrom($format);

        return CrmLabel::enum($format) ?? __('Не указан');
    }

    private static function statusLabel(BookingStatus|string $status): string
    {
        $status = $status instanceof BookingStatus ? $status : BookingStatus::tryFrom($status);

        return CrmLabel::enum($status) ?? __('Не указан');
    }

    private static function paymentRequirementLabel(PaymentRequirementType|string|null $requirement): string
    {
        $requirement = $requirement instanceof PaymentRequirementType || $requirement === null
            ? $requirement
            : PaymentRequirementType::tryFrom($requirement);

        return match ($requirement) {
            PaymentRequirementType::FullPayment => __('Полная оплата'),
            PaymentRequirementType::TransportDeposit => __('Депозит за выезд'),
            default => __('Не указано'),
        };
    }

    private static function bookingSummary(Booking $booking): ?BookingFinanceSummary
    {
        return app(FinancePresentation::class)->bookingSummary($booking);
    }

    private static function viewerTimezone(): string
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? app(ResolveSpecialistViewerTimezone::class)->forUser($actor)
            : app(OrganizationContext::class)->defaultTimezone();
    }

    private static function locationLabel(Booking $booking): string
    {
        $snapshot = $booking->locationSnapshot();

        return match ($booking->visit_format) {
            VisitFormat::Office => trim(implode("\n", array_filter([
                $snapshot['name'] ?? __('Кабинет'),
                $snapshot['address'] ?? $booking->location,
            ]))) ?: __('Не указано'),
            VisitFormat::HomeVisit => trim(implode("\n", [
                __('Выезд').($booking->location_area !== null ? ' · '.$booking->location_area : ''),
                __('Адрес клиента: :address', ['address' => $snapshot['address'] ?? $booking->location ?? __('не указан')]),
            ])),
            VisitFormat::Online => __('Онлайн'),
        };
    }

    /** @param array<string, mixed> $values */
    private static function safeValue(array $values, string $key): ?string
    {
        return isset($values[$key]) && is_string($values[$key]) ? $values[$key] : null;
    }
}
