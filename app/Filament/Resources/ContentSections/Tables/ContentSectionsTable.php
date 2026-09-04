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
                TextColumn::make('section_key')
                    ->label('Раздел')
                    ->formatStateUsing(fn (string $state): string => self::sectionLabel($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('locale')
                    ->label('Язык')
                    ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский')
                    ->sortable(),
                TextColumn::make('title')->label('Название')->searchable(),
                TextColumn::make('sort_order')->label('Порядок показа')->sortable(),
                IconColumn::make('is_visible')->label('Показывать')->boolean()->sortable(),
                TextColumn::make('media')
                    ->label('Изображение')
                    ->state(fn (ContentSection $record): string => $record->media === null ? '—' : 'Добавлено'),
            ])
            ->filters([
                TernaryFilter::make('is_visible')->label('Показывать'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    private static function sectionLabel(string $section): string
    {
        return match ($section) {
            'author' => 'Об академии',
            'method' => 'Методика',
            'b2b' => 'Для бизнеса',
            'partner' => 'Партнёрам',
            'communities' => 'Сообщества',
            'hidden' => 'Скрытый раздел',
            default => 'Раздел',
        };
    }
}
