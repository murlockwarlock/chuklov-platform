<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\TimezoneOptions;
use App\Modules\Analytics\Application\ClientSegmentQuery;
use App\Modules\Analytics\Domain\Enums\ClientSegment;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        $canViewClients = ClientResource::canViewAny();

        return $table
            ->searchable()
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
            ->searchPlaceholder(__('Имя, email, телефон или ID клиента'))
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
                TextColumn::make('full_name')
                    ->label(__('Имя'))
                    ->sortable()
                    ->wrap()
                    ->url(fn (Client $record): ?string => CrmEntityLinks::clientUrl($record, $canViewClients))
                    ->color(fn (Client $record): ?string => CrmEntityLinks::clientUrl($record, $canViewClients) === null ? null : 'primary')
                    ->disabledClick(fn (Client $record): bool => CrmEntityLinks::clientUrl($record, $canViewClients) === null),
                TextColumn::make('phone')->label(__('Телефон'))->fontFamily('mono')->placeholder('—')->visibleFrom('sm'),
                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('channel_identities_count')
                    ->label(__('Способы связи'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('timezone')
                    ->label(__('Часовой пояс'))
                    ->formatStateUsing(fn (?string $state): string => TimezoneOptions::label($state))
                    ->sortable()
                    ->visibleFrom('lg'),
                TextColumn::make('language')
                    ->label(__('Язык'))
                    ->formatStateUsing(fn (string $state): string => $state === 'ru' ? __('Русский') : __('Английский'))
                    ->sortable()
                    ->toggleable()
                    ->visibleFrom('sm'),
                IconColumn::make('activeBookingRestriction')
                    ->label(__('Запись'))
                    ->boolean()
                    ->state(fn (Client $record): bool => $record->activeBookingRestriction === null)
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->tooltip(fn (Client $record): string => $record->activeBookingRestriction === null
                        ? __('Запись разрешена')
                        : __('Запись ограничена: :reason', ['reason' => $record->activeBookingRestriction->reason])),
            ])
            ->filters([
                SelectFilter::make('segment')
                    ->label(__('Категория'))
                    ->options(ClientSegment::labels())
                    ->query(fn (Builder $query, array $data): Builder => app(ClientSegmentQuery::class)->apply($query, $data['value'] ?? null)),
                TernaryFilter::make('activeBookingRestriction')
                    ->label(__('Самостоятельная запись'))
                    ->placeholder(__('Все клиенты'))
                    ->trueLabel(__('Только разрешённые'))
                    ->falseLabel(__('Только с ограничениями'))
                    ->queries(
                        true: fn ($query) => $query->whereDoesntHave('activeBookingRestriction'),
                        false: fn ($query) => $query->whereHas('activeBookingRestriction'),
                    ),
            ])
            ->emptyStateHeading(__('Клиентов пока нет'))
            ->emptyStateDescription(__('Добавьте клиента вручную или он появится автоматически после первой записи.'))
            ->recordActions([
                ViewAction::make()
                    ->label(__('Открыть'))
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip(__('Открыть клиента')),
                EditAction::make()
                    ->label(__('Редактировать'))
                    ->icon(Heroicon::OutlinedPencil)
                    ->iconButton()
                    ->tooltip(__('Редактировать клиента')),
            ]);
    }
}
