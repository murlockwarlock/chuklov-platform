<?php

namespace App\Filament\Resources\LocationDays\Tables;

use App\Filament\Support\FormValidationFeedback;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Scheduling\Application\SaveLocationDay;
use App\Modules\Scheduling\Domain\Models\LocationDay;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Throwable;

class LocationDaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('specialist.display_name')->label(__('Специалист'))->placeholder(__('Все специалисты'))->searchable()->sortable(),
                TextColumn::make('area_name')->label(__('Район'))->searchable()->sortable(),
                TextColumn::make('weekday')->label(__('День'))->formatStateUsing(fn (?int $state): string => self::weekdayLabel($state)),
                TextColumn::make('specific_date')->label(__('Дата'))->date('d.m.Y'),
                TextColumn::make('start_time')->label(__('Начало')),
                TextColumn::make('end_time')->label(__('Окончание')),
                TextColumn::make('timezone')
                    ->label(__('Часовой пояс'))
                    ->formatStateUsing(fn (?string $state): string => TimezoneOptions::display($state)),
                IconColumn::make('is_active')->label(__('Активен'))->boolean(),
            ])
            ->defaultSort('area_name')
            ->recordActions([
                EditAction::make()->label(__('Редактировать')),
                Action::make('deactivate')
                    ->label(__('Отключить правило'))
                    ->color('danger')
                    ->icon('heroicon-o-eye-slash')
                    ->requiresConfirmation()
                    ->modalHeading(__('Отключить правило выезда?'))
                    ->modalDescription(__('Это правило перестанет использоваться при подборе новых записей.'))
                    ->visible(fn (LocationDay $record): bool => $record->is_active)
                    ->action(function (Action $action, LocationDay $record): void {
                        try {
                            self::setActive($record, false);
                            Notification::make()->success()->title(__('Правило выезда отключено'))->send();
                        } catch (ValidationException $exception) {
                            $mapped = FormValidationFeedback::forAction($exception, $action);
                            FormValidationFeedback::notifyValidation($mapped, __('Не удалось выполнить действие. Попробуйте ещё раз.'));
                            throw $mapped;
                        } catch (Throwable $exception) {
                            $mapped = FormValidationFeedback::unexpectedForAction($exception, $action);
                            FormValidationFeedback::notifyValidation($mapped);
                            throw $mapped;
                        }
                    }),
                Action::make('activate')
                    ->label(__('Включить правило'))
                    ->color('success')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->visible(fn (LocationDay $record): bool => ! $record->is_active)
                    ->action(function (Action $action, LocationDay $record): void {
                        try {
                            self::setActive($record, true);
                            Notification::make()->success()->title(__('Правило выезда включено'))->send();
                        } catch (ValidationException $exception) {
                            $mapped = FormValidationFeedback::forAction($exception, $action);
                            FormValidationFeedback::notifyValidation($mapped, __('Не удалось выполнить действие. Попробуйте ещё раз.'));
                            throw $mapped;
                        } catch (Throwable $exception) {
                            $mapped = FormValidationFeedback::unexpectedForAction($exception, $action);
                            FormValidationFeedback::notifyValidation($mapped);
                            throw $mapped;
                        }
                    }),
            ]);
    }

    private static function setActive(LocationDay $record, bool $isActive): LocationDay
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(SaveLocationDay::class)->handle(
            actor: $actor,
            locationDay: $record,
            areaName: (string) $record->area_name,
            weekday: $record->weekday === null ? null : (int) $record->weekday,
            specificDate: $record->specific_date === null ? null : (string) $record->specific_date,
            startTime: (string) $record->start_time,
            endTime: (string) $record->end_time,
            timezone: (string) $record->timezone,
            isActive: $isActive,
            notes: $record->notes,
            specialistId: $record->specialist_id === null ? null : (int) $record->specialist_id,
        );
    }

    private static function weekdayLabel(?int $weekday): string
    {
        return [
            1 => __('Пн'),
            2 => __('Вт'),
            3 => __('Ср'),
            4 => __('Чт'),
            5 => __('Пт'),
            6 => __('Сб'),
            7 => __('Вс'),
        ][$weekday ?? 0] ?? '';
    }
}
