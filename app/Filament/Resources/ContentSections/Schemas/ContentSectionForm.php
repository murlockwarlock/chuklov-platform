<?php

namespace App\Filament\Resources\ContentSections\Schemas;

use App\Filament\Support\RichTextEditor;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Content\Domain\Models\ContentSection;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContentSectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основная информация')
                    ->schema([
                        Select::make('section_key')
                            ->label('Раздел')
                            ->options([
                                'author' => 'Об академии',
                                'method' => 'Методика',
                                'b2b' => 'Для бизнеса',
                                'partner' => 'Партнёрам',
                                'hidden' => 'Скрытый раздел',
                            ])
                            ->searchable()
                            ->required(),
                        Select::make('locale')
                            ->options([
                                'en' => 'Английский',
                                'ru' => 'Русский',
                            ])
                            ->label('Язык')
                            ->required(),
                        TextInput::make('title')
                            ->label('Название')
                            ->required()
                            ->maxLength(160)
                            ->columnSpanFull(),
                        Select::make('delivery_mode')
                            ->label('Где показывать')
                            ->options([
                                ContentDeliveryMode::Telegram->value => 'Telegram',
                                ContentDeliveryMode::MiniApp->value => 'Mini App',
                                ContentDeliveryMode::Both->value => 'Telegram и Mini App',
                            ])
                            ->required()
                            ->default(ContentDeliveryMode::Both->value),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Текст раздела')
                    ->schema([
                        RichTextEditor::make('body')
                            ->label('Текст')
                            ->required()
                            ->maxLength(100000)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Изображение')
                    ->description('Можно загрузить файл или указать готовую ссылку на изображение.')
                    ->schema([
                        FileUpload::make('content_image')
                            ->label('Загрузить изображение')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(self::imageUploadKilobytes())
                            ->storeFiles(false)
                            ->validationMessages([
                                'mimetypes' => 'Поддерживаются только изображения JPG, PNG и WebP.',
                                'max' => 'Изображение должно быть размером до 5 МБ.',
                            ])
                            ->helperText('JPG, PNG или WebP размером до 5 МБ.')
                            ->columnSpanFull(),
                        TextInput::make('media.image')
                            ->label('Ссылка на изображение')
                            ->url()
                            ->maxLength(2000)
                            ->dehydrated(fn (mixed $state): bool => filled($state))
                            ->helperText('Заполните только если не загружаете файл.')
                            ->columnSpanFull(),
                        TextInput::make('media.alt')
                            ->label('Описание изображения')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Placeholder::make('current_image_status')
                            ->label('Текущее изображение')
                            ->content('Изображение уже добавлено.')
                            ->visible(fn (?ContentSection $record): bool => self::hasImage($record))
                            ->columnSpanFull(),
                        Toggle::make('remove_image')
                            ->label('Удалить текущее изображение')
                            ->visible(fn (?ContentSection $record): bool => self::hasImage($record))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Показ и порядок')
                    ->schema([
                        TextInput::make('sort_order')
                            ->label('Порядок показа')
                            ->integer()
                            ->dehydrateStateUsing(fn (mixed $state): ?int => $state === null ? null : (int) $state)
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(PHP_INT_MAX),
                        Toggle::make('is_visible')
                            ->label('Показывать')
                            ->required()
                            ->default(true),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function imageUploadKilobytes(): int
    {
        $bytes = max(1, (int) config('content_media.max_bytes', 5_242_880));

        return intdiv($bytes + 1023, 1024);
    }

    private static function hasImage(?ContentSection $record): bool
    {
        $media = $record?->media;
        $image = is_array($media) ? $media['image'] ?? null : null;

        return is_string($image) && trim($image) !== '';
    }
}
