<?php

namespace App\Filament\Resources\ContentSections\Schemas;

use App\Filament\Support\RichTextEditor;
use App\Filament\Support\TelegramPreviewAction;
use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationActionButton;
use App\Modules\Channels\Domain\ValueObjects\NotificationMedia;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Content\Application\ContentImageUrlResolver;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Support\RichText\RichTextDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Image as SchemaImage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

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
                                'communities' => 'Сообщества',
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
                            ->live(debounce: 300)
                            ->columnSpanFull(),
                        Actions::make([
                            TelegramPreviewAction::make(fn (Get $get, ?Model $record): NotificationMessage => self::previewMessage($get, $record)),
                        ])->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Изображение')
                    ->description('Можно загрузить изображение или указать готовую HTTPS-ссылку. Новая загрузка или ссылка заменит текущее изображение после сохранения.')
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
                            ->helperText('JPG, PNG или WebP размером до 5 МБ. Видео и несколько файлов не поддерживаются.')
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('remove_image', false);
                            })
                            ->columnSpanFull(),
                        TextInput::make('media.image')
                            ->label('Ссылка на изображение')
                            ->url()
                            ->maxLength(2000)
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('remove_image', false);
                            })
                            ->dehydrated(fn (mixed $state): bool => filled($state))
                            ->helperText('Заполните только если не загружаете файл.')
                            ->columnSpanFull(),
                        SchemaImage::make(
                            fn (?ContentSection $record): string => self::imagePreviewUrl($record) ?? '',
                            fn (?ContentSection $record): string => self::imageAlt($record),
                        )
                            ->imageHeight('16rem')
                            ->imageWidth('24rem')
                            ->extraAttributes(['class' => 'max-w-full rounded-xl object-contain'])
                            ->visible(fn (?ContentSection $record, Get $get): bool => self::imagePreviewUrl($record) !== null && ! $get('remove_image'))
                            ->columnSpanFull(),
                        Placeholder::make('current_image_status')
                            ->label('Текущее изображение')
                            ->content(fn (?ContentSection $record): string => self::imageStatus($record))
                            ->visible(fn (?ContentSection $record, Get $get): bool => self::hasImage($record) && ! $get('remove_image'))
                            ->columnSpanFull(),
                        Hidden::make('remove_image')->default(false),
                        Actions::make([
                            Action::make('removeImage')
                                ->label('Удалить текущее изображение')
                                ->icon('heroicon-o-trash')
                                ->color('danger')
                                ->action(function (Set $set): void {
                                    $set('remove_image', true);
                                })
                                ->visible(fn (?ContentSection $record, Get $get): bool => self::hasImage($record) && ! $get('remove_image')),
                            Action::make('restoreImage')
                                ->label('Оставить текущее изображение')
                                ->icon('heroicon-o-arrow-uturn-left')
                                ->color('gray')
                                ->action(function (Set $set): void {
                                    $set('remove_image', false);
                                })
                                ->visible(fn (?ContentSection $record, Get $get): bool => self::hasImage($record) && $get('remove_image')),
                        ])
                            ->columnSpanFull(),
                        Placeholder::make('image_removal_notice')
                            ->label('Изменение изображения')
                            ->content('Текущее изображение будет удалено после сохранения.')
                            ->visible(fn (?ContentSection $record, Get $get): bool => self::hasImage($record) && $get('remove_image'))
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

    private static function imagePreviewUrl(?ContentSection $record): ?string
    {
        if (! $record instanceof ContentSection) {
            return null;
        }

        return app(ContentImageUrlResolver::class)->resolve($record);
    }

    private static function imageAlt(?ContentSection $record): string
    {
        $media = $record?->media;
        $alt = is_array($media) ? $media['alt'] ?? null : null;

        if (is_string($alt) && trim($alt) !== '') {
            return trim($alt);
        }

        $title = $record instanceof ContentSection ? trim($record->title) : '';

        return $title !== '' ? $title : 'Изображение раздела';
    }

    private static function imageStatus(?ContentSection $record): string
    {
        if (! $record instanceof ContentSection) {
            return 'Изображение не добавлено.';
        }

        $kind = app(ContentImageUrlResolver::class)->isManaged($record)
            ? 'Загруженный файл'
            : 'Внешняя HTTPS-ссылка';

        return $kind.'. Новая загрузка или ссылка заменит текущее изображение после сохранения.';
    }

    private static function previewMessage(Get $get, ?Model $record): NotificationMessage
    {
        $title = trim((string) $get('title'));
        $body = trim((string) $get('body'));
        $content = $title === '' ? $body : '<p><strong>'.htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</strong></p>'.$body;
        $content = $content === '' ? '' : RichTextDocument::canonicalHtml($content);
        $media = self::previewMedia($get, $record);
        $mode = $media === []
            ? NotificationMessageMode::Text
            : (RichTextDocument::telegramLength($content) <= RichTextDocument::TELEGRAM_CAPTION_LIMIT
                ? NotificationMessageMode::ImageWithCaption
                : NotificationMessageMode::ImageThenText);
        $deliveryMode = (string) $get('delivery_mode');
        $button = $deliveryMode === 'both'
            ? new NotificationActionButton(
                text: $get('locale') === 'en' ? 'Open full version' : 'Открыть полностью',
                url: app(ResolveTelegramMiniAppEntry::class)->launchUrl((string) $get('section_key')),
            )
            : null;

        return new NotificationMessage(
            recipientExternalId: 'preview',
            body: $content,
            subject: null,
            locale: (string) ($get('locale') ?: 'ru'),
            idempotencyKey: 'content-preview',
            actionButton: $button,
            mode: $mode,
            mediaItems: $media,
        );
    }

    /** @return list<NotificationMedia> */
    private static function previewMedia(Get $get, ?Model $record): array
    {
        if ((bool) $get('remove_image')) {
            return [];
        }

        $upload = $get('content_image');
        if ($upload instanceof UploadedFile && method_exists($upload, 'temporaryUrl')) {
            try {
                $url = $upload->temporaryUrl();
            } catch (\Throwable) {
                $url = null;
            }

            if (is_string($url) && trim($url) !== '') {
                return [new NotificationMedia('photo', url: $url, fileName: $upload->getClientOriginalName() ?: null)];
            }
        }

        $url = $get('media.image');
        if (is_string($url) && trim($url) !== '') {
            return [new NotificationMedia('photo', url: trim($url))];
        }

        if ($record instanceof ContentSection) {
            $url = app(ContentImageUrlResolver::class)->resolve($record);
            if ($url !== null) {
                return [new NotificationMedia('photo', url: $url)];
            }
        }

        return [];
    }
}
