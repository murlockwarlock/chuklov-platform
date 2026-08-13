<?php

namespace App\Filament\Resources\NotificationTemplates\Tables;

use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class NotificationTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('template_key')->label('Template')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('locale')->sortable(),
                TextColumn::make('purpose')->badge(),
                TextColumn::make('latest_version')
                    ->label('Latest version')
                    ->state(function (NotificationTemplate $record): string {
                        $latest = $record->versions->sortByDesc('version')->first();

                        return 'v'.($latest === null ? '—' : $latest->version);
                    }),
                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
