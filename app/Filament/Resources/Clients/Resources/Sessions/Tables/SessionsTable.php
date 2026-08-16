<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Tables;

use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class SessionsTable
{
    public static function configure(Table $table): Table
    {
        $canViewSessions = MedicalSessionResource::canViewAny();
        $canManageSessions = MedicalSessionResource::canCreate();

        return $table
            ->paginated([25, 50])
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Дата сеанса')
                    ->dateTime('d.m.Y H:i'),
                TextColumn::make('specialist.display_name')
                    ->label('Специалист')
                    ->placeholder('—'),
                TextColumn::make('booking_starts_at')
                    ->label('Дата записи на приём')
                    ->state(fn ($record): ?string => $record->booking === null
                        ? null
                        : Carbon::parse((string) $record->booking->getAttribute('starts_at'))->format('d.m.Y H:i'))
                    ->placeholder('Не связан'),
                TextColumn::make('booking_status')
                    ->label('Статус записи')
                    ->state(fn ($record): ?string => $record->booking === null ? null : self::statusLabel($record->booking->status))
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Открыть')
                    ->visible($canViewSessions)
                    ->url(fn ($record): string => MedicalSessionResource::getUrl('view', ['record' => $record], shouldGuessMissingParameters: true)),
                Action::make('edit')
                    ->label('Редактировать')
                    ->visible($canManageSessions)
                    ->url(fn ($record): string => MedicalSessionResource::getUrl('edit', ['record' => $record], shouldGuessMissingParameters: true)),
            ])
            ->toolbarActions([
                Action::make('create')
                    ->label('Новый сеанс')
                    ->icon('heroicon-o-plus')
                    ->url(fn (): string => MedicalSessionResource::getUrl('create', shouldGuessMissingParameters: true))
                    ->visible($canManageSessions),
            ]);
    }

    private static function statusLabel(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Requested => 'Ожидает подтверждения',
            BookingStatus::PendingReview => 'На рассмотрении',
            BookingStatus::Confirmed => 'Подтверждена',
            BookingStatus::Rejected => 'Отклонена',
            BookingStatus::Cancelled => 'Отменена',
            BookingStatus::Completed => 'Завершена',
            BookingStatus::NoShow => 'Не состоялась',
        };
    }
}
