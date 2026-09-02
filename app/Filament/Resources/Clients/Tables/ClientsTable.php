<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Filament\Support\TimezoneOptions;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->searchable()
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
            ->searchPlaceholder('Имя, email, телефон или ID клиента')
            ->searchUsing(function (Builder $query, string $search): void {
                app(ClientSearch::class)->apply($query, $search);
            })
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->formatStateUsing(fn (int|string $state): string => '#'.$state)
                    ->fontFamily('mono')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('full_name')->label('Имя')->sortable()->wrap(),
                TextColumn::make('phone')->label('Телефон')->fontFamily('mono')->placeholder('—'),
                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('channel_identities_count')
                    ->label('Способы связи')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('timezone')
                    ->label('Часовой пояс')
                    ->formatStateUsing(fn (?string $state): string => TimezoneOptions::label($state))
                    ->sortable()
                    ->visibleFrom('lg'),
                TextColumn::make('language')
                    ->label('Язык')
                    ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('activeBookingRestriction')
                    ->label('Запись')
                    ->boolean()
                    ->state(fn (Client $record): bool => $record->activeBookingRestriction === null)
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->tooltip(fn (Client $record): string => $record->activeBookingRestriction === null ? 'Запись разрешена' : 'Запись ограничена: '.$record->activeBookingRestriction->reason),
            ])
            ->filters([
                TernaryFilter::make('activeBookingRestriction')
                    ->label('Самостоятельная запись')
                    ->placeholder('Все клиенты')
                    ->trueLabel('Только разрешённые')
                    ->falseLabel('Только с ограничениями')
                    ->queries(
                        true: fn ($query) => $query->whereDoesntHave('activeBookingRestriction'),
                        false: fn ($query) => $query->whereHas('activeBookingRestriction'),
                    ),
            ])
            ->emptyStateHeading('Клиентов пока нет')
            ->emptyStateDescription('Добавьте клиента вручную или он появится автоматически после первой записи.')
            ->recordActions([
                ViewAction::make()
                    ->label('Открыть')
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip('Открыть клиента'),
                EditAction::make()
                    ->label('Редактировать')
                    ->icon(Heroicon::OutlinedPencil)
                    ->iconButton()
                    ->tooltip('Редактировать клиента'),
            ]);
    }
}
