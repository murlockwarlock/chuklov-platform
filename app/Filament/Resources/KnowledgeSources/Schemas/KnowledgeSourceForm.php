<?php

namespace App\Filament\Resources\KnowledgeSources\Schemas;

use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class KnowledgeSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Основная информация')
                ->description('Материалы помогают специалистам находить нужную информацию при работе с клиентами.')
                ->schema([
                    TextInput::make('title')->label('Название')->required()->maxLength(200),
                    Select::make('type')->label('Тип')->options([
                        KnowledgeSourceType::AuthoredText->value => 'Текст организации',
                        KnowledgeSourceType::UploadedText->value => 'TXT или Markdown',
                    ])->required()->live()->disabledOn('edit'),
                    TextInput::make('category')->label('Категория')->maxLength(80),
                ])->columns(2)->columnSpanFull(),
            Section::make('Материал')->schema([
                Textarea::make('content')->label('Текст')->rows(18)->maxLength(500000)->required(fn (Get $get): bool => $get('type') === KnowledgeSourceType::AuthoredText->value)->visible(fn (Get $get): bool => $get('type') === KnowledgeSourceType::AuthoredText->value)->columnSpanFull(),
                FileUpload::make('file')->label('Файл')->helperText('База знаний принимает только TXT/Markdown: файл безопасно извлекается в текст, разбивается на фрагменты и индексируется. PDF и изображения для разового AI-вложения настраиваются отдельно. При редактировании оставьте поле пустым, чтобы сохранить текущий материал.')->acceptedFileTypes(config('rag.uploads.allowed_mime_types'))->maxSize((int) config('rag.uploads.maximum_kilobytes'))->storeFiles(false)->required(fn (Get $get, string $operation): bool => $operation === 'create' && $get('type') === KnowledgeSourceType::UploadedText->value)->visible(fn (Get $get): bool => $get('type') === KnowledgeSourceType::UploadedText->value)->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }
}
