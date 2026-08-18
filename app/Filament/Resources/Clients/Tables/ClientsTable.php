<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Filament\Support\TimezoneOptions;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
            ->searchable()
            ->searchPlaceholder('Имя, email, телефон или ID клиента')
            ->searchUsing(function (Builder $query, string $search): void {
                app(ClientSearch::class)->apply($query, $search);
            })
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->formatStateUsing(fn (int|string $state): string => '#'.$state)
                    ->sortable(),
                TextColumn::make('full_name')->label('Имя')->sortable(),
                TextColumn::make('email')->label('Email')->placeholder('—'),
                TextColumn::make('phone')->label('Телефон')->placeholder('—'),
                TextColumn::make('language')
                    ->label('Язык')
                    ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский')
                    ->sortable(),
                TextColumn::make('timezone')
                    ->label('Часовой пояс')
                    ->formatStateUsing(fn (?string $state): string => TimezoneOptions::label($state))
                    ->sortable(),
                TextColumn::make('channel_identities_count')->label('Способы связи')->sortable(),
                IconColumn::make('activeBookingRestriction')
                    ->label('Самостоятельная запись заблокирована')
                    ->boolean()
                    ->state(fn (Client $record): bool => $record->activeBookingRestriction !== null),
            ])
            ->filters([
                TernaryFilter::make('activeBookingRestriction')
                    ->label('Самостоятельная запись заблокирована')
                    ->queries(
                        true: fn ($query) => $query->whereHas('activeBookingRestriction'),
                        false: fn ($query) => $query->whereDoesntHave('activeBookingRestriction'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
