<?php

namespace App\Filament\Resources\ScenarioActions\Tables;

use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ScenarioActionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('rule.name')->label('Правило')->searchable(),
                TextColumn::make('event.event_name')
                    ->label('Когда')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::eventLabel($state)),
                TextColumn::make('client.full_name')->label('Клиент')->placeholder('Сотрудник')->searchable(),
                TextColumn::make('recipientUser.name')->label('Сотрудник')->placeholder('—'),
                TextColumn::make('scheduled_for')->label('Запланировано')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('sequence_number')
                    ->label('В серии')
                    ->formatStateUsing(fn (mixed $state, ScenarioAction $record): string => $state.' из '.$record->max_occurrences),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::statusLabel($state))
                    ->sortable(),
                TextColumn::make('terminal_reason')
                    ->label('Результат')
                    ->formatStateUsing(fn (?string $state): string => self::reasonLabel($state))
                    ->placeholder('—'),
            ])
            ->recordActions([ViewAction::make()->label('Открыть')]);
    }

    private static function statusLabel(mixed $status): string
    {
        $value = $status instanceof BackedEnum ? $status->value : (string) $status;

        return match ($value) {
            'scheduled' => 'Запланировано',
            'processing' => 'Отправляется',
            'delivered' => 'Отправлено',
            'retryable' => 'Повторим позже',
            'failed', 'suppressed' => 'Не отправлено',
            'cancelled' => 'Отменено',
            default => 'Неизвестный статус',
        };
    }

    private static function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            'current_conditions_not_met' => 'Условие больше не выполнено',
            'provider_suppressed' => 'Получатель отключил сообщения',
            'recipient_unavailable' => 'Получатель недоступен',
            'no_available_channel' => 'Нет доступного канала',
            null => '—',
            default => 'Не удалось отправить',
        };
    }

    private static function eventLabel(mixed $event): string
    {
        $value = $event instanceof BackedEnum ? $event->value : (string) $event;

        return match ($value) {
            'booking.created' => 'После новой записи',
            'booking.confirmed' => 'После подтверждения записи',
            'booking.rescheduled' => 'После переноса записи',
            'booking.cancelled' => 'После отмены записи',
            'booking.completed' => 'После визита',
            default => 'Событие',
        };
    }
}
