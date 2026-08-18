<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Filament\Resources\Bookings\Actions\BookingLifecycleActions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Modules\Scheduling\Application\BookingNeedsAttention;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
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
                ActionGroup::make(BookingLifecycleActions::all())
                    ->label('Действия')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->button()
                    ->color('gray')
                    ->size('sm'),
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
