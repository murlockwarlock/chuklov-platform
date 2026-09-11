<?php

namespace App\Filament\Support;

use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Support\RichText\RichTextDocument;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

use function Filament\Support\generate_icon_html;

final class MessageComposer
{
    /**
     * @param  array<string, string>|Closure(Get): array<string, string>|null  $variables
     * @param  Closure(Get, ?Model): mixed  $preview
     * @param  Closure(): array<int, mixed>|null  $savedTemplateSchema
     * @param  array<int, mixed>  $additionalMediaComponents
     * @param  array<int, string>|null  $mediaAcceptedFileTypes
     * @return array<int, Section>
     */
    public static function make(
        string $bodyField,
        string $deliveryModeField,
        string $mediaField,
        string $mediaUrlField,
        array|Closure|null $variables,
        Closure $preview,
        bool $allowSavedTemplates = false,
        ?Closure $savedTemplateSchema = null,
        array $additionalMediaComponents = [],
        string $bodyLabel = 'Текст сообщения',
        string $bodyHelper = 'Используйте форматирование, ссылки, эмодзи и данные из списка доступных переменных.',
        bool $requireMedia = false,
        bool $showDeliveryMode = true,
        bool $includeMediaUrl = true,
        ?array $mediaAcceptedFileTypes = null,
        ?int $mediaMaxKilobytes = null,
        bool $mediaMultiple = true,
        string $mediaSectionTitle = 'Медиа',
        ?string $mediaSectionDescription = null,
        string $mediaUploadLabel = 'Загрузить медиа',
        ?string $mediaHelperText = null,
        ?string $mediaSelectionField = null,
        bool $mediaUsesCaptionLimit = false,
        bool $compact = false,
    ): array {
        $messageModeField = 'message_mode';

        $messageComponents = [];
        if ($showDeliveryMode) {
            $messageComponents[] = Radio::make($deliveryModeField)
                ->label('Формат отправки')
                ->options(self::deliveryOptions())
                ->default(NotificationMessageMode::Text->value)
                ->live()
                ->required()
                ->columns(1)
                ->columnSpanFull();
        } else {
            $messageComponents[] = Hidden::make($deliveryModeField)
                ->default(NotificationMessageMode::Text->value);
        }

        if (! $compact) {
            $messageComponents[] =
                Radio::make('caption_position')
                    ->label('Положение подписи')
                    ->options(['above' => 'Над медиа', 'below' => 'Под медиа'])
                    ->default('below')
                    ->inline()
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => self::usesCaption($get, $deliveryModeField))
                    ->required(fn (Get $get): bool => self::usesCaption($get, $deliveryModeField));
        }

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

        $bodyEditor = $compact
            ? Textarea::make($bodyField)
                ->label($bodyLabel)
                ->hiddenLabel()
                ->placeholder('Напишите сообщение...')
                ->rows(1)
                ->autosize()
            : RichTextEditor::make($bodyField, $variables)
                ->label($bodyLabel);

        $messageComponents[] = $bodyEditor
            ->maxLength(100000)
            ->live(debounce: 300)
            ->helperText($compact ? null : $bodyHelper)
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => self::bodyIsEditable($get, $deliveryModeField, $allowSavedTemplates, $messageModeField))
            ->required(fn (Get $get): bool => self::bodyIsEditable($get, $deliveryModeField, $allowSavedTemplates, $messageModeField));
        if (! $compact) {
            $messageComponents[] = Placeholder::make('message_counter')
                ->label('Лимит Telegram')
                ->content(fn (Get $get): string => self::messageCounter(
                    $get,
                    $bodyField,
                    $deliveryModeField,
                    $mediaField,
                    $mediaUrlField,
                    $mediaSelectionField,
                    $mediaUsesCaptionLimit,
                ))
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => self::bodyIsEditable($get, $deliveryModeField, $allowSavedTemplates, $messageModeField));
            $messageComponents[] = Placeholder::make('message_preview')
                ->label('Предпросмотр')
                ->content(fn (Get $get): string => self::messagePreview($get, $bodyField))
                ->prose()
                ->html()
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => self::bodyIsEditable($get, $deliveryModeField, $allowSavedTemplates, $messageModeField));
        }

        if ($allowSavedTemplates && $savedTemplateSchema !== null) {
            foreach ($savedTemplateSchema() as $component) {
                $messageComponents[] = $component;
            }
        }

        if (! $compact) {
            $messageComponents[] = Actions::make([
                TelegramPreviewAction::make($preview),
            ])->columnSpanFull();
        }

        $fileUpload = FileUpload::make($mediaField)
            ->label($mediaUploadLabel)
            ->multiple($mediaMultiple)
            ->maxFiles($mediaMultiple ? 10 : 1)
            ->reorderable()
            ->appendFiles()
            ->openable()
            ->previewable()
            ->deletable()
            ->maxSize($mediaMaxKilobytes ?? self::mediaUploadKilobytes())
            ->storeFiles(false)
            ->live()
            ->required(fn (Get $get): bool => $requireMedia
                && self::usesMedia($get, $deliveryModeField)
                && ! self::hasUploads($get, $mediaField)
                && blank($get($mediaUrlField)))
            ->afterStateUpdated(function (Set $set): void {
                $set('remove_media', false);
            })
            ->helperText($compact ? null : ($mediaHelperText ?? 'Фото до 10 МБ; MP4 и другие файлы до 50 МБ. От 2 до 10 фото или видео одного типа отправятся альбомом Telegram.'))
            ->columnSpanFull();
        if ($mediaAcceptedFileTypes !== null) {
            $fileUpload->acceptedFileTypes($mediaAcceptedFileTypes);
        }

        if ($compact) {
            $fileUpload
                ->hiddenLabel()
                ->placeholder(generate_icon_html(Heroicon::OutlinedPaperClip)?->toHtml() ?? '')
                ->extraAttributes(['class' => 'messages-attachment-upload'])
                ->extraInputAttributes(['aria-label' => 'Добавить вложение']);
        }

        $mediaComponents = [
            $fileUpload,
            ...($compact ? [] : $additionalMediaComponents),
            Hidden::make('remove_media')->default(false),
        ];
        if ($includeMediaUrl) {
            array_splice($mediaComponents, 1, 0, [
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
            ]);
        }

        if ($compact) {
            $compactMessageComponents = array_values(array_filter(
                $messageComponents,
                static fn (mixed $component): bool => $component !== $bodyEditor,
            ));
            $compactMessageComponents[] = Flex::make([
                $bodyEditor->grow(),
                $fileUpload->grow(false),
                Action::make('sendMessage')
                    ->label('Отправить')
                    ->submit('sendMessage')
                    ->extraAttributes(['class' => 'messages-send-action']),
            ])
                ->dense()
                ->verticallyAlignCenter()
                ->extraAttributes(['class' => 'messages-composer-fields']);

            return [
                Section::make()
                    ->schema([
                        ...$compactMessageComponents,
                        ...array_slice($mediaComponents, 1),
                    ])
                    ->columns(1)
                    ->compact()
                    ->columnSpanFull(),
            ];
        }

        return [
            Section::make($compact ? null : 'Сообщение')
                ->schema($messageComponents)
                ->columns(1)
                ->compact($compact)
                ->columnSpanFull(),
            Section::make($compact ? null : $mediaSectionTitle)
                ->description($compact ? null : ($mediaSectionDescription ?? 'Можно отправить только медиа, медиа с подписью или текст и медиа в выбранном порядке. Telegram ограничивает подпись 1024 символами, текст — 4096.'))
                ->schema($mediaComponents)
                ->compact($compact)
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

    private static function messageCounter(
        Get $get,
        string $bodyField,
        string $deliveryModeField,
        string $mediaField,
        string $mediaUrlField,
        ?string $mediaSelectionField,
        bool $mediaUsesCaptionLimit,
    ): string {
        $mode = NotificationMessageMode::tryFrom((string) $get($deliveryModeField));
        $hasMedia = $mediaUsesCaptionLimit && (
            self::hasUploads($get, $mediaField)
            || filled($get($mediaUrlField))
            || ($mediaSelectionField !== null && filled($get($mediaSelectionField)))
        );
        $limit = $mode?->usesCaption() === true || $hasMedia
            ? RichTextDocument::TELEGRAM_CAPTION_LIMIT
            : RichTextDocument::TELEGRAM_TEXT_LIMIT;

        try {
            $body = RichTextDocument::canonicalHtmlFromState($get($bodyField));
            if ($body === '') {
                return '0 / '.$limit;
            }

            return RichTextDocument::telegramLength($body).' / '.$limit;
        } catch (\InvalidArgumentException) {
            return 'Проверьте формат текста · лимит '.$limit;
        }
    }

    private static function messagePreview(Get $get, string $bodyField): string
    {
        try {
            return RichTextPresentation::html(RichTextDocument::canonicalHtmlFromState($get($bodyField)))
                ?: 'Текст появится здесь.';
        } catch (\InvalidArgumentException) {
            return 'Проверьте формат текста.';
        }
    }

    private static function mediaUploadKilobytes(): int
    {
        $bytes = max(1, (int) config('broadcast_media.max_bytes', 52_428_800));

        return intdiv($bytes + 1023, 1024);
    }
}
