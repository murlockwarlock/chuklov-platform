<?php

namespace App\Filament\Resources\ContentSections\Tables;

use App\Modules\Content\Application\ContentVideoUrlResolver;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Content\Domain\Models\ContentSection;
use Filament\Actions\CreateAction;
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
                TextColumn::make('home_status')
                    ->label(__('Статус показа'))
                    ->state(fn (ContentSection $record): string => self::homeStatus($record)),
                TextColumn::make('media')
                    ->label(__('Медиа'))
                    ->state(fn (ContentSection $record): string => $record->media === null ? '—' : __('Добавлено')),
            ])
            ->filters([
                TernaryFilter::make('is_visible')->label(__('Показывать')),
            ])
            ->emptyStateHeading(__('Контент ещё не настроен'))
            ->emptyStateDescription(__('Добавьте раздел, чтобы управлять текстом и медиа. Для видео на главной выберите один из разделов «Видео», включите показ и загрузите MP4.'))
            ->emptyStateActions([
                CreateAction::make()->label(__('Добавить раздел')),
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
            'story_author' => __('Кто такой Евгений Чуклов (видео)'),
            'story_history' => __('История мастера (видео)'),
            'story_method' => __('Авторский метод (видео)'),
            'story_details' => __('Подробно о методике (видео)'),
            'telegram_welcome' => __('Приветствие Telegram'),
            default => __('Раздел'),
        };
    }

    private static function homeStatus(ContentSection $record): string
    {
        if (! self::isStoryKey($record->section_key)) {
            return __('Не применяется');
        }
        if (! $record->is_visible) {
            return __('Не опубликовано');
        }
        if (! in_array($record->delivery_mode, [ContentDeliveryMode::MiniApp, ContentDeliveryMode::Both], true)) {
            return __('Не показывается на главной: включите показ в Portal');
        }
        if (app(ContentVideoUrlResolver::class)->resolve($record) === null) {
            return __('Видео не загружено');
        }

        return __('Показывается на главной');
    }

    private static function isStoryKey(string $key): bool
    {
        return in_array($key, ['story_author', 'story_history', 'story_method', 'story_details'], true);
    }
}
