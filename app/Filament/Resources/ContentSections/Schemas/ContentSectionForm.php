<?php

namespace App\Filament\Resources\ContentSections\Schemas;

use App\Filament\Support\RichTextEditor;
use App\Filament\Support\TelegramPreviewAction;
use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationMedia;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Content\Application\ContentImageUrlResolver;
use App\Modules\Content\Application\ContentVideoUrlResolver;
use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Support\RichText\RichTextDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Support\HtmlString;

class ContentSectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Основная информация'))
                    ->schema([
                        Select::make('section_key')
                            ->label(__('Раздел'))
                            ->options([
                                'author' => __('Об академии'),
                                'method' => __('Методика'),
                                'b2b' => __('Для бизнеса'),
                                'partner' => __('Партнёрам'),
                                'communities' => __('Сообщества'),
                                'hidden' => __('Скрытый раздел'),
                                'telegram_welcome' => __('Приветствие Telegram'),
                                'story_author' => __('Кто такой Евгений Чуклов (видео)'),
                                'story_history' => __('История мастера (видео)'),
                                'story_method' => __('Авторский метод (видео)'),
                                'story_details' => __('Подробно о методике (видео)'),
                            ])
                            ->searchable()
                            ->required(),
                        Select::make('locale')
                            ->options([
                                'en' => __('Английский'),
                                'ru' => __('Русский'),
                            ])
                            ->label(__('Язык'))
                            ->required(),
                        TextInput::make('title')
                            ->label(__('Название'))
                            ->required()
                            ->maxLength(160)
                            ->columnSpanFull(),
                        Select::make('delivery_mode')
                            ->label(__('Где показывать'))
                            ->options([
                                ContentDeliveryMode::Telegram->value => 'Telegram',
                                ContentDeliveryMode::MiniApp->value => 'Mini App',
                                ContentDeliveryMode::Both->value => __('Telegram и Mini App'),
                            ])
                            ->required()
                            ->default(ContentDeliveryMode::Both->value),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make(__('Текст раздела'))
                    ->schema([
                        RichTextEditor::make('body')
                            ->label(__('Текст'))
                            ->required()
                            ->maxLength(100000)
                            ->columnSpanFull(),
                        Actions::make([
                            TelegramPreviewAction::make(fn (Get $get, ?Model $record): NotificationMessage => self::previewMessage($get, $record)),
                        ])->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make(__('Видео'))
                    ->description(__('Сохраните раздел, затем загрузите MP4 через кнопку «Загрузить видео». Для приветствия — до 60 секунд.'))
                    ->schema([
                        TextInput::make('media.subtitles')
                            ->label(__('Ссылка на субтитры WebVTT'))
                            ->helperText(__('Или загрузите файл кнопкой «Загрузить субтитры» после сохранения раздела.'))
                            ->dehydrated(fn (?ContentSection $record, mixed $state): bool => filled($state) || $record === null || ! app(ContentMediaStorageInterface::class)->isManagedPath((int) $record->organization_id, $record->media['subtitles'] ?? null))
                            ->url()
                            ->startsWith('https://')
                            ->nullable()
                            ->maxLength(2000)
                            ->validationMessages([
                                'url' => __('Укажите корректную ссылку на файл WebVTT.'),
                                'starts_with' => __('Ссылка на файл субтитров должна начинаться с https://.'),
                            ]),
                        Textarea::make('media.caption')
                            ->label(__('Подпись к видео приветствия'))
                            ->helperText(__('Необязательная подпись, которая отправляется вместе с видео в Telegram.'))
                            ->rows(3)
                            ->maxLength(1024)
                            ->nullable()
                            ->visible(fn (Get $get): bool => $get('section_key') === 'telegram_welcome'),
                        Placeholder::make('current_video')->label(__('Загруженное видео'))
                            ->content(function (?ContentSection $record): HtmlString|string {
                                if ($record === null) {
                                    return (string) __('Сначала сохраните раздел.');
                                }
                                $url = app(ContentVideoUrlResolver::class)->resolve($record);

                                return $url === null ? (string) __('Видео не загружено') : new HtmlString('<video controls preload="metadata" style="max-width:100%;max-height:320px" src="'.e($url).'"></video>');
                            }),
                    ])->columnSpanFull(),
                Section::make(__('Официальные сообщества'))
                    ->description(__('Добавьте ссылки на официальные каналы и группы. Неактивные записи не показываются клиентам.'))
                    ->schema([
                        Repeater::make('media.communities')
                            ->label(__('Список сообществ'))
                            ->schema([
                                TextInput::make('title')
                                    ->label(__('Название'))
                                    ->required()
                                    ->maxLength(160),
                                TextInput::make('url')
                                    ->label(__('Ссылка Telegram или сайта'))
                                    ->required()
                                    ->url()
                                    ->startsWith('https://')
                                    ->maxLength(2000),
                                Textarea::make('description')
                                    ->label(__('Описание'))
                                    ->rows(2)
                                    ->maxLength(500)
                                    ->nullable(),
                                TextInput::make('sort_order')
                                    ->label(__('Порядок'))
                                    ->integer()
                                    ->minValue(0)
                                    ->default(0),
                                Toggle::make('is_visible')
                                    ->label(__('Показывать'))
                                    ->default(true),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel(__('Добавить сообщество'))
                            ->visible(fn (Get $get): bool => $get('section_key') === 'communities'),
                    ])
                    ->columnSpanFull(),
                Section::make(__('Изображение'))
                    ->description(__('Можно загрузить изображение или указать готовую ссылку. Новая загрузка или ссылка заменит текущее изображение после сохранения.'))
                    ->schema([
                        FileUpload::make('content_image')
                            ->label(__('Загрузить изображение'))
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(self::imageUploadKilobytes())
                            ->storeFiles(false)
                            ->validationMessages([
                                'mimetypes' => __('Поддерживаются только изображения JPG, PNG и WebP.'),
                                'max' => __('Изображение должно быть размером до 5 МБ.'),
                            ])
                            ->helperText(__('JPG, PNG или WebP размером до 5 МБ. Видео и несколько файлов не поддерживаются.'))
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('remove_image', false);
                            })
                            ->columnSpanFull(),
                        TextInput::make('media.image')
                            ->label(__('Ссылка на изображение'))
                            ->url()
                            ->maxLength(2000)
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('remove_image', false);
                            })
                            ->dehydrated(fn (mixed $state): bool => filled($state))
                            ->helperText(__('Заполните только если не загружаете файл.'))
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
                            ->label(__('Текущее изображение'))
                            ->content(fn (?ContentSection $record): string => self::imageStatus($record))
                            ->visible(fn (?ContentSection $record, Get $get): bool => self::hasImage($record) && ! $get('remove_image'))
                            ->columnSpanFull(),
                        Hidden::make('remove_image')->default(false),
                        Actions::make([
                            Action::make('removeImage')
                                ->label(__('Удалить текущее изображение'))
                                ->icon('heroicon-o-trash')
                                ->color('danger')
                                ->action(function (Set $set): void {
                                    $set('remove_image', true);
                                })
                                ->visible(fn (?ContentSection $record, Get $get): bool => self::hasImage($record) && ! $get('remove_image')),
                            Action::make('restoreImage')
                                ->label(__('Оставить текущее изображение'))
                                ->icon('heroicon-o-arrow-uturn-left')
                                ->color('gray')
                                ->action(function (Set $set): void {
                                    $set('remove_image', false);
                                })
                                ->visible(fn (?ContentSection $record, Get $get): bool => self::hasImage($record) && $get('remove_image')),
                        ])
                            ->columnSpanFull(),
                        Placeholder::make('image_removal_notice')
                            ->label(__('Изменение изображения'))
                            ->content(__('Текущее изображение будет удалено после сохранения.'))
                            ->visible(fn (?ContentSection $record, Get $get): bool => self::hasImage($record) && $get('remove_image'))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make(__('Показ и порядок'))
                    ->schema([
                        TextInput::make('sort_order')
                            ->label(__('Порядок показа'))
                            ->integer()
                            ->dehydrateStateUsing(fn (mixed $state): ?int => $state === null ? null : (int) $state)
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(PHP_INT_MAX),
                        Toggle::make('is_visible')
                            ->label(__('Показывать'))
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

        return $title !== '' ? $title : __('Изображение раздела');
    }

    private static function imageStatus(?ContentSection $record): string
    {
        if (! $record instanceof ContentSection) {
            return __('Изображение не добавлено.');
        }

        $kind = app(ContentImageUrlResolver::class)->isManaged($record)
            ? __('Загруженный файл')
            : __('Внешняя ссылка');

        return __(':kind. Новая загрузка или ссылка заменит текущее изображение после сохранения.', ['kind' => $kind]);
    }

    private static function previewMessage(Get $get, ?Model $record): NotificationMessage
    {
        $title = trim((string) $get('title'));
        $body = RichTextDocument::canonicalHtmlFromState($get('body'));
        $content = $title === '' ? $body : '<p><strong>'.htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</strong></p>'.$body;
        $content = $content === '' ? '' : RichTextDocument::canonicalHtml($content);
        $media = self::previewMedia($get, $record);
        $mode = $media === []
            ? NotificationMessageMode::Text
            : (RichTextDocument::telegramLength($content) <= RichTextDocument::TELEGRAM_CAPTION_LIMIT
                ? NotificationMessageMode::ImageWithCaption
                : NotificationMessageMode::ImageThenText);
        $deliveryMode = (string) $get('delivery_mode');
        $webAppUrl = $deliveryMode === 'both'
            ? app(ResolveTelegramMiniAppEntry::class)->launchUrl((string) $get('section_key'))
            : null;
        $translatedWebAppButtonText = __('Открыть полностью');
        $webAppButtonText = $webAppUrl === null
            ? null
            : (is_string($translatedWebAppButtonText) ? $translatedWebAppButtonText : 'Открыть полностью');

        return new NotificationMessage(
            recipientExternalId: 'preview',
            body: $content,
            subject: null,
            locale: (string) ($get('locale') ?: 'ru'),
            idempotencyKey: 'content-preview',
            webAppUrl: $webAppUrl,
            webAppButtonText: $webAppButtonText,
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
