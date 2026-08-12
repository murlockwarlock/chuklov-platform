<?php

namespace App\Filament\Resources\ContentSections\Tables;

use App\Modules\Content\Domain\Models\ContentSection;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ContentSectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('section_key')->label('Section')->searchable()->sortable(),
                TextColumn::make('locale')->sortable(),
                TextColumn::make('title')->searchable(),
                TextColumn::make('sort_order')->label('Order')->sortable(),
                IconColumn::make('is_visible')->boolean()->sortable(),
                TextColumn::make('media')
                    ->label('Media')
                    ->state(fn (ContentSection $record): string => $record->media === null ? '—' : 'Configured'),
            ])
            ->filters([
                TernaryFilter::make('is_visible')->label('Visible'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
