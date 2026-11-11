<?php

namespace App\Filament\Resources\WorkingLocations\Tables;

use App\Models\User;
use App\Modules\Scheduling\Application\UpdateWorkingLocation;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkingLocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Название'))->searchable()->sortable(),
                TextColumn::make('address')->label(__('Адрес'))->wrap(),
                TextColumn::make('timezone')->label(__('Часовой пояс'))->sortable(),
                IconColumn::make('is_default_office')->label(__('Основная'))->boolean(),
                IconColumn::make('is_active')->label(__('Активна'))->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->label(__('Редактировать')),
                Action::make('deactivate')
                    ->label(__('Скрыть локацию'))
                    ->color('danger')
                    ->icon('heroicon-o-eye-slash')
                    ->requiresConfirmation()
                    ->modalHeading(__('Скрыть локацию?'))
                    ->modalDescription(__('Локация перестанет предлагаться для новых записей. Уже созданные записи не изменятся.'))
                    ->visible(fn (WorkingLocation $record): bool => $record->is_active)
                    ->action(function (WorkingLocation $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(UpdateWorkingLocation::class)->handle(
                            actor: $actor,
                            location: $record,
                            name: (string) $record->name,
                            address: (string) $record->address,
                            timezone: (string) $record->timezone,
                            latitude: $record->latitude === null ? null : (float) $record->latitude,
                            longitude: $record->longitude === null ? null : (float) $record->longitude,
                            mapUrl: $record->map_url,
                            isActive: false,
                            isDefaultOffice: false,
                        );
                    }),
                Action::make('activate')
                    ->label(__('Вернуть локацию в запись'))
                    ->color('success')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->modalHeading(__('Вернуть локацию в запись?'))
                    ->visible(fn (WorkingLocation $record): bool => ! $record->is_active)
                    ->action(function (WorkingLocation $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(UpdateWorkingLocation::class)->handle(
                            actor: $actor,
                            location: $record,
                            name: (string) $record->name,
                            address: (string) $record->address,
                            timezone: (string) $record->timezone,
                            latitude: $record->latitude === null ? null : (float) $record->latitude,
                            longitude: $record->longitude === null ? null : (float) $record->longitude,
                            mapUrl: $record->map_url,
                            isActive: true,
                            isDefaultOffice: (bool) $record->is_default_office,
                        );
                    }),
            ]);
    }
}
