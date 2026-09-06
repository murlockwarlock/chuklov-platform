<?php

namespace App\Filament\Support;

use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Support\RichText\RichTextDocument;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

final class MessageComposer
{
    /**
     * @param  array<string, string>|Closure(Get): array<string, string>  $variables
     * @param  Closure(Get, ?Model): mixed  $preview
     * @param  Closure(): array<int, mixed>|null  $savedTemplateSchema
     * @param  array<int, mixed>  $additionalMediaComponents
     * @return array<int, Section>
     */
    public static function make(
        string $bodyField,
        string $deliveryModeField,
        string $mediaField,
        string $mediaUrlField,
        array|Closure $variables,
        Closure $preview,
        bool $allowSavedTemplates = false,
        ?Closure $savedTemplateSchema = null,
        array $additionalMediaComponents = [],
        string $bodyLabel = 'Текст сообщения',
        string $bodyHelper = 'Используйте форматирование, ссылки, эмодзи и данные из списка доступных переменных.',
        bool $requireMedia = false,
    ): array {
        $messageModeField = 'message_mode';

        $messageComponents = [
            Radio::make($deliveryModeField)
                ->label('Формат отправки')
                ->options(self::deliveryOptions())
                ->default(NotificationMessageMode::Text->value)
                ->live()
                ->required()
                ->columns(1)
                ->columnSpanFull(),
            Radio::make('caption_position')
                ->label('Положение подписи')
                ->options(['above' => 'Над медиа', 'below' => 'Под медиа'])
                ->default('below')
                ->inline()
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => self::usesCaption($get, $deliveryModeField))
                ->required(fn (Get $get): bool => self::usesCaption($get, $deliveryModeField)),
        ];

        if ($allowSavedTemplates) {
            $messageComponents[] = Radio::make($messageModeField)
                ->label('Источник текста')
                ->options([
                    'compose' => 'Написать сообщение',
                    'saved_template' => 'Использовать сохранённый шаблон',
                ])
                ->default('compose')
                ->live()
                ->inline()
                ->helperText('Для разовой отправки оставьте «Написать сообщение».')
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => self::includesText($get, $deliveryModeField))
                ->required(fn (Get $get): bool => self::includesText($get, $deliveryModeField));
        }

        $messageComponents[] = RichTextEditor::make($bodyField, $variables)
            ->label($bodyLabel)
            ->maxLength(100000)
            ->live()
            ->helperText($bodyHelper)
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => self::bodyIsEditable($get, $deliveryModeField, $allowSavedTemplates, $messageModeField))
            ->required(fn (Get $get): bool => self::bodyIsEditable($get, $deliveryModeField, $allowSavedTemplates, $messageModeField));
        $messageComponents[] = Placeholder::make('message_counter')
            ->label('Лимит Telegram')
            ->content(fn (Get $get): string => self::messageCounter($get, $bodyField, $deliveryModeField))
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => self::bodyIsEditable($get, $deliveryModeField, $allowSavedTemplates, $messageModeField));
        $messageComponents[] = Placeholder::make('message_preview')
            ->label('Предпросмотр')
            ->content(fn (Get $get): string => RichTextPresentation::html((string) $get($bodyField)) ?: 'Текст появится здесь.')
            ->prose()
            ->html()
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => self::bodyIsEditable($get, $deliveryModeField, $allowSavedTemplates, $messageModeField));

        if ($allowSavedTemplates && $savedTemplateSchema !== null) {
            foreach ($savedTemplateSchema() as $component) {
                $messageComponents[] = $component;
            }
        }

        $messageComponents[] = Actions::make([
            TelegramPreviewAction::make($preview),
        ])->columnSpanFull();

        $mediaComponents = [
            FileUpload::make($mediaField)
                ->label('Загрузить медиа')
                ->multiple()
                ->maxFiles(10)
                ->reorderable()
                ->appendFiles()
                ->openable()
                ->previewable()
                ->deletable()
                ->maxSize(self::mediaUploadKilobytes())
                ->storeFiles(false)
                ->live()
                ->required(fn (Get $get): bool => $requireMedia
                    && self::usesMedia($get, $deliveryModeField)
                    && ! self::hasUploads($get, $mediaField)
                    && blank($get($mediaUrlField)))
                ->afterStateUpdated(function (Set $set): void {
                    $set('remove_media', false);
                })
                ->helperText('Фото до 10 МБ; MP4 и другие файлы до 50 МБ. От 2 до 10 фото или видео одного типа отправятся альбомом Telegram.')
                ->columnSpanFull(),
            TextInput::make($mediaUrlField)
                ->label('HTTPS-ссылка на медиа (одно)')
                ->url()
                ->maxLength(2000)
                ->live()
                ->required(fn (Get $get): bool => $requireMedia
                    && self::usesMedia($get, $deliveryModeField)
                    && ! self::hasUploads($get, $mediaField))
                ->afterStateUpdated(function (Set $set): void {
                    $set('remove_media', false);
                })
                ->dehydrated(fn (mixed $state): bool => filled($state))
                ->helperText('Ссылка должна вести непосредственно на файл и начинаться с HTTPS.')
                ->columnSpanFull(),
            ...$additionalMediaComponents,
            Hidden::make('remove_media')->default(false),
        ];

        return [
            Section::make('Сообщение')
                ->schema($messageComponents)
                ->columns(1)
                ->columnSpanFull(),
            Section::make('Медиа')
                ->description('Можно отправить только медиа, медиа с подписью или текст и медиа в выбранном порядке. Telegram ограничивает подпись 1024 символами, текст — 4096.')
                ->schema($mediaComponents)
                ->columnSpanFull(),
        ];
    }

    /** @return array<string, string> */
    private static function deliveryOptions(): array
    {
        return [
            NotificationMessageMode::Text->value => 'Только текст',
            NotificationMessageMode::Image->value => 'Только медиа',
            NotificationMessageMode::ImageThenText->value => 'Медиа, затем текст',
            NotificationMessageMode::TextThenImage->value => 'Текст, затем медиа',
            NotificationMessageMode::ImageWithCaption->value => 'Медиа с подписью',
        ];
    }

    private static function includesText(Get $get, string $deliveryModeField): bool
    {
        return NotificationMessageMode::tryFrom((string) $get($deliveryModeField))?->includesText() ?? true;
    }

    private static function usesCaption(Get $get, string $deliveryModeField): bool
    {
        return NotificationMessageMode::tryFrom((string) $get($deliveryModeField))?->usesCaption() ?? false;
    }

    private static function usesMedia(Get $get, string $deliveryModeField): bool
    {
        return NotificationMessageMode::tryFrom((string) $get($deliveryModeField))?->includesImage() ?? false;
    }

    private static function hasUploads(Get $get, string $mediaField): bool
    {
        $uploads = $get($mediaField);

        return $uploads instanceof UploadedFile
            || (is_array($uploads) && $uploads !== []);
    }

    private static function bodyIsEditable(Get $get, string $deliveryModeField, bool $allowSavedTemplates, string $messageModeField): bool
    {
        if (! self::includesText($get, $deliveryModeField)) {
            return false;
        }

        return ! $allowSavedTemplates || $get($messageModeField) !== 'saved_template';
    }

    private static function messageCounter(Get $get, string $bodyField, string $deliveryModeField): string
    {
        $mode = NotificationMessageMode::tryFrom((string) $get($deliveryModeField));
        $limit = $mode?->usesCaption() === true
            ? RichTextDocument::TELEGRAM_CAPTION_LIMIT
            : RichTextDocument::TELEGRAM_TEXT_LIMIT;
        $body = $get($bodyField);

        if (! is_string($body) || trim($body) === '') {
            return '0 / '.$limit;
        }

        try {
            return RichTextDocument::telegramLength($body).' / '.$limit;
        } catch (\InvalidArgumentException) {
            return 'Проверьте формат текста · лимит '.$limit;
        }
    }

    private static function mediaUploadKilobytes(): int
    {
        $bytes = max(1, (int) config('broadcast_media.max_bytes', 52_428_800));

        return intdiv($bytes + 1023, 1024);
    }
}
