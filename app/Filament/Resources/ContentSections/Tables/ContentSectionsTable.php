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
                    ->label(__('Раздел'))
                    ->formatStateUsing(fn (string $state): string => self::sectionLabel($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('locale')
                    ->label(__('Язык'))
                    ->formatStateUsing(fn (string $state): string => $state === 'ru' ? __('Русский') : __('Английский'))
                    ->sortable(),
                TextColumn::make('title')->label(__('Название'))->searchable(),
                TextColumn::make('sort_order')->label(__('Порядок показа'))->sortable(),
                IconColumn::make('is_visible')->label(__('Показывать'))->boolean()->sortable(),
                TextColumn::make('media')
                    ->label(__('Изображение'))
                    ->state(fn (ContentSection $record): string => $record->media === null ? '—' : __('Добавлено')),
            ])
            ->filters([
                TernaryFilter::make('is_visible')->label(__('Показывать')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    private static function sectionLabel(string $section): string
    {
        return match ($section) {
            'author' => __('Об академии'),
            'method' => __('Методика'),
            'b2b' => __('Для бизнеса'),
            'partner' => __('Партнёрам'),
            'communities' => __('Сообщества'),
            'hidden' => __('Скрытый раздел'),
            default => __('Раздел'),
        };
    }
}
