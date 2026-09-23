<?php

namespace App\Filament\Resources\KnowledgeSources\Schemas;

use App\Filament\Support\KnowledgeSourcePresentation;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class KnowledgeSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Основная информация'))
                ->description(__('Материалы помогают специалистам находить нужную информацию при работе с клиентами.'))
                ->schema([
                    TextInput::make('title')->label(__('Название'))->required()->maxLength(200),
                    Select::make('type')->label(__('Тип'))->options([
                        KnowledgeSourceType::AuthoredText->value => __('Текст организации'),
                        KnowledgeSourceType::UploadedText->value => __('Файл: TXT, Markdown, PDF или таблица'),
                    ])->required()->live()->disabledOn('edit'),
                    TextInput::make('category')->label(__('Категория'))->maxLength(80),
                    Toggle::make('client_companion_enabled')
                        ->label(__('Можно использовать в ответах клиентского AI-помощника'))
                        ->helperText(__('Включайте только материалы, которые безопасно показывать клиентам.'))
                        ->default(false),
                    Placeholder::make('material_status')
                        ->label(__('Материал'))
                        ->content(fn (?KnowledgeSource $record): string => $record instanceof KnowledgeSource
                            ? app(KnowledgeSourcePresentation::class)->materialStatus($record)
                            : __('Будет использоваться после сохранения')),
                    Placeholder::make('search_status')
                        ->label(__('Состояние поиска'))
                        ->content(fn (?KnowledgeSource $record): string => $record instanceof KnowledgeSource
                            ? app(KnowledgeSourcePresentation::class)->searchAvailability($record)
                            : __('Появится после обработки материала')),
                    Placeholder::make('semantic_search_status')
                        ->label(__('Семантический поиск'))
                        ->content(fn (): string => app(KnowledgeSourcePresentation::class)->semanticSearchSummary())
                        ->columnSpanFull(),
                ])->columns(2)->columnSpanFull(),
            Section::make(__('Материал'))->schema([
                Textarea::make('content')->label(__('Текст'))->rows(18)->maxLength(500000)->required(fn (Get $get): bool => $get('type') === KnowledgeSourceType::AuthoredText->value)->visible(fn (Get $get): bool => $get('type') === KnowledgeSourceType::AuthoredText->value)->columnSpanFull(),
                FileUpload::make('file')->label(__('Файл'))->helperText(__('Поддерживаются TXT, Markdown, текстовые PDF, CSV, XLSX, XLS и ODS. Текст и таблицы извлекаются локально. Для сканированного PDF будет доступен отдельный явный запрос AI-разбора. При редактировании оставьте поле пустым, чтобы сохранить текущий материал.'))->acceptedFileTypes(config('rag.uploads.allowed_mime_types'))->maxSize((int) config('rag.uploads.maximum_kilobytes'))->storeFiles(false)->required(fn (Get $get, string $operation): bool => $operation === 'create' && $get('type') === KnowledgeSourceType::UploadedText->value)->visible(fn (Get $get): bool => $get('type') === KnowledgeSourceType::UploadedText->value)->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }
}
