<?php

namespace App\Filament\Resources\KnowledgeSources\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'История версий';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->label('Версия'),
                TextColumn::make('status')->label('Состояние')->formatStateUsing(fn ($state): string => match ($state->value ?? $state) {
                    'pending' => 'Ожидает обработки',
                    'processing' => 'Обрабатывается',
                    'ready' => 'Готова',
                    'failed' => 'Ошибка',
                    'stale' => 'Предыдущая',
                    'retired' => 'Скрыта',
                    default => 'Неизвестно',
                }),
                TextColumn::make('latestIngestionRun.error_code')->label('Результат обработки')->formatStateUsing(fn (?string $state): string => match ($state) {
                    'invalid_source_content' => 'Файл повреждён или изменён',
                    'source_text_too_large' => 'Слишком большой объём текста',
                    'empty_source_content' => 'В документе нет текста',
                    'embedding_or_persistence_failed' => 'Обработка не завершена',
                    default => 'Без ошибок',
                })->placeholder('Без ошибок'),
                TextColumn::make('source_reference')->label('Источник')->limit(60)->placeholder('Не указан'),
                TextColumn::make('created_at')->label('Создана')->dateTime('d.m.Y H:i'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('latestIngestionRun'))
            ->defaultSort('version', 'desc')
            ->paginated([10, 25]);
    }
}
