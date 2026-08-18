<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\User;
use App\Modules\Scheduling\Application\ApproveHomeVisitBooking;
use App\Modules\Scheduling\Application\BookingNeedsAttention;
use App\Modules\Scheduling\Application\CancelBooking;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Application\ConfirmBooking;
use App\Modules\Scheduling\Application\MarkBookingNoShow;
use App\Modules\Scheduling\Application\RejectHomeVisitBooking;
use App\Modules\Scheduling\Application\RescheduleBooking;
use App\Modules\Scheduling\Application\SetOnlineMeetingUrl;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\PaymentRequirementType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BookingsTable
{
    public static function configure(Table $table, bool $includeAttention = true, bool $includeClient = true): Table
    {
        $canManageScheduling = BookingResource::canCreate();
        $columns = [
            TextColumn::make('specialist.display_name')->label('Специалист')->sortable()->wrap(),
            TextColumn::make('service.name')->label('Услуга')->sortable()->wrap(),
            TextColumn::make('starts_at')->label('Дата и время')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('visit_format')
                ->label('Формат')
                ->formatStateUsing(fn (VisitFormat|string $state): string => self::formatLabel($state)),
            TextColumn::make('status')
                ->label('Статус')
                ->badge()
                ->formatStateUsing(fn (BookingStatus|string $state): string => self::statusLabel($state))
                ->sortable()
                ->wrap(),
        ];

        if ($includeClient) {
            array_unshift($columns, TextColumn::make('client.full_name')->label('Клиент')->searchable()->sortable()->wrap());
        }

        if ($includeAttention) {
            $columns[] = TextColumn::make('needs_attention')
                ->label('Проверка времени')
                ->badge()
                ->state(fn (Booking $record): string => app(BookingNeedsAttention::class)->handle($record) ? 'Требует внимания' : 'В порядке')
                ->color(fn (string $state): string => $state === 'Требует внимания' ? 'danger' : 'success');
        }

        return $table
            ->stackedOnMobile()
            ->columns($columns)
            ->recordActions([
                ViewAction::make()->label('Открыть'),
                Action::make('approveHomeVisit')
                    ->label('Подтвердить выезд')
                    ->color('success')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Комментарий')
                            ->maxLength(500),
                        Select::make('payment_requirement')
                            ->label('Условие оплаты')
                            ->options([
                                PaymentRequirementType::FullPayment->value => 'Полная оплата',
                                PaymentRequirementType::TransportDeposit->value => 'Депозит за выезд',
                            ])
                            ->nullable(),
                    ])
                    ->visible(fn (Booking $record): bool => $canManageScheduling
                        && $record->status === BookingStatus::PendingReview
                        && $record->visit_format === VisitFormat::HomeVisit)
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(ApproveHomeVisitBooking::class)->handle(
                            $actor,
                            $record,
                            $data['reason'] ?? null,
                            $data['payment_requirement'] ?? null,
                        );
                    }),
                Action::make('rejectHomeVisit')
                    ->label('Отклонить заявку')
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Причина отказа')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->visible(fn (Booking $record): bool => $canManageScheduling
                        && $record->status === BookingStatus::PendingReview
                        && $record->visit_format === VisitFormat::HomeVisit)
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(RejectHomeVisitBooking::class)->handle($actor, $record, (string) $data['reason']);
                    }),
                Action::make('confirm')
                    ->label('Подтвердить запись')
                    ->color('success')
                    ->schema([Textarea::make('reason')->label('Комментарий')->maxLength(500)])
                    ->visible(fn (Booking $record): bool => $canManageScheduling
                        && $record->status === BookingStatus::Requested
                        && in_array($record->visit_format, [VisitFormat::Office, VisitFormat::Online], true))
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(ConfirmBooking::class)->handle($actor, $record, $data['reason'] ?? null);
                    }),
                Action::make('cancel')
                    ->label('Отменить')
                    ->color('danger')
                    ->schema([Textarea::make('reason')->label('Причина')->maxLength(500)])
                    ->visible(fn (Booking $record): bool => $canManageScheduling
                        && ! in_array($record->status->value, BookingStatus::terminalValues(), true))
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(CancelBooking::class)->handle($actor, $record, $data['reason'] ?? null);
                    }),
                Action::make('reschedule')
                    ->label('Перенести')
                    ->schema([
                        DateTimePicker::make('starts_at')->label('Новая дата и время')->seconds(false)->required(),
                        Hidden::make('expected_event_version')
                            ->default(fn (Booking $record): int => $record->event_version)
                            ->required(),
                        Textarea::make('reason')->label('Причина')->maxLength(500),
                    ])
                    ->visible(fn (Booking $record): bool => $canManageScheduling
                        && ! in_array($record->status->value, BookingStatus::terminalValues(), true))
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        $startsAt = $data['starts_at'] instanceof \DateTimeInterface
                            ? $data['starts_at']
                            : CarbonImmutable::parse((string) $data['starts_at']);

                        app(RescheduleBooking::class)->handle(
                            actor: $actor,
                            booking: $record,
                            newStartsAt: $startsAt,
                            clientTimezone: null,
                            reason: $data['reason'] ?? null,
                            expectedEventVersion: (int) $data['expected_event_version'],
                        );
                    }),
                Action::make('complete')
                    ->label('Завершить визит')
                    ->color('success')
                    ->schema([Textarea::make('reason')->label('Комментарий')->maxLength(500)])
                    ->visible(fn (Booking $record): bool => $canManageScheduling && $record->status === BookingStatus::Confirmed)
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(CompleteBooking::class)->handle($actor, $record, $data['reason'] ?? null);
                    }),
                Action::make('noShow')
                    ->label('Отметить неявку')
                    ->color('danger')
                    ->schema([Textarea::make('reason')->label('Комментарий')->maxLength(500)])
                    ->visible(fn (Booking $record): bool => $canManageScheduling
                        && in_array($record->status, [BookingStatus::Requested, BookingStatus::Confirmed], true))
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(MarkBookingNoShow::class)->handle($actor, $record, $data['reason'] ?? null);
                    }),
                Action::make('meetingUrl')
                    ->label('Добавить ссылку на встречу')
                    ->schema([
                        TextInput::make('meeting_url')->label('Ссылка на встречу')->url()->required()->maxLength(2000),
                        Textarea::make('reason')->label('Комментарий')->maxLength(500),
                    ])
                    ->visible(fn (Booking $record): bool => $canManageScheduling
                        && $record->visit_format === VisitFormat::Online
                        && $record->meeting_link_mode?->value === 'manual'
                        && in_array($record->status, [BookingStatus::Requested, BookingStatus::Confirmed], true))
                    ->action(function (Booking $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(SetOnlineMeetingUrl::class)->handle($actor, $record, (string) $data['meeting_url'], $data['reason'] ?? null);
                    }),
            ]);
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
}
