<?php

namespace App\Filament\Resources\NotificationTemplates\Schemas;

use App\Filament\Support\MessageComposer;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationMedia;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateRenderer;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

final class NotificationTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основная информация')
                    ->description('Сообщение может состоять из текста, медиа или их комбинации. Формат и подпись сохраняются в версии шаблона.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(160),
                        Select::make('locale')
                            ->options([
                                'ru' => 'Русский',
                                'en' => 'Английский',
                            ])
                            ->label('Язык')
                            ->required()
                            ->default('ru')
                            ->disabled(fn (string $operation): bool => $operation === 'edit'),
                        Select::make('purpose')
                            ->label('Для чего сообщение')
                            ->options([
                                ScenarioRulePurpose::Service->value => 'Сервисное сообщение',
                                ScenarioRulePurpose::Transactional->value => 'Системное сообщение',
                                ScenarioRulePurpose::Marketing->value => 'Маркетинговая рассылка',
                            ])
                            ->helperText('Категория сообщения. Получатель и время отправки настраиваются отдельно в авто-сообщении.')
                            ->live()
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Включён')
                            ->helperText('Новые отправки с этим шаблоном создаваться не будут. Уже запланированные сообщения также не будут отправлены, пока шаблон отключён.')
                            ->required()
                            ->default(true),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                TextInput::make('subject')
                    ->label('Тема')
                    ->maxLength(255)
                    ->helperText('Необязательно для мессенджеров. Можно использовать подстановочные данные.')
                    ->columnSpanFull(),
                ...MessageComposer::make(
                    bodyField: 'body',
                    deliveryModeField: 'delivery_mode',
                    mediaField: 'media_image',
                    mediaUrlField: 'media_url',
                    variables: fn (Get $get): array => ScenarioTemplateVariableCatalog::labelsForPurpose($get('purpose')),
                    preview: fn (Get $get, ?Model $record): NotificationMessage => self::previewMessage($get, $record),
                    additionalMediaComponents: [
                        Placeholder::make('template_current_media')
                            ->label('Сохранённое медиа')
                            ->content(fn (?NotificationTemplate $record): string => self::currentMediaSummary($record))
                            ->visible(fn (?NotificationTemplate $record): bool => $record instanceof NotificationTemplate && $record->latestVersion?->media !== null)
                            ->columnSpanFull(),
                    ],
                    bodyLabel: 'Текст сообщения',
                    bodyHelper: 'Используйте форматирование, ссылки, эмодзи и данные из списка доступных переменных.',
                    requireMedia: true,
                ),
            ]);
    }

    private static function previewMessage(Get $get, ?Model $record): NotificationMessage
    {
        $body = (string) $get('body');
        $subject = (string) $get('subject');
        $variables = ScenarioTemplateVariableCatalog::used($body, $subject);
        $template = new NotificationTemplateVersion;
        $template->forceFill([
            'body' => $body,
            'subject' => $subject === '' ? null : $subject,
            'variables' => $variables,
            'delivery_mode' => NotificationMessageMode::tryFrom((string) $get('delivery_mode')) ?? NotificationMessageMode::Text,
            'caption_position' => (string) ($get('caption_position') ?: 'below'),
        ]);
        $locale = (string) ($get('locale') ?: 'ru');
        $rendered = app(NotificationTemplateRenderer::class)->render(
            $template,
            [
                'client' => ['full_name' => 'Aikhana', 'language' => $locale],
                'referral_link' => 'https://t.me/chuklov_test_bot?start=ref_preview',
            ],
            $locale,
        );

        return new NotificationMessage(
            recipientExternalId: 'preview',
            body: $rendered->body,
            subject: $rendered->subject,
            locale: $locale,
            idempotencyKey: 'template-preview',
            mode: $rendered->mode,
            showCaptionAboveMedia: $rendered->showCaptionAboveMedia,
            mediaItems: self::previewMediaItems($get),
        );
    }

    private static function currentMediaSummary(?NotificationTemplate $template): string
    {
        $media = $template?->latestVersion?->media;
        $items = is_array($media) && is_array($media['items'] ?? null) ? $media['items'] : [];

        return $items === [] ? 'Медиа не добавлено.' : 'Сохранено файлов: '.count($items).'. Новые файлы создадут новую версию шаблона.';
    }

    /** @return list<NotificationMedia> */
    private static function previewMediaItems(Get $get): array
    {
        $uploads = $get('media_image');
        $uploads = $uploads instanceof UploadedFile ? [$uploads] : (is_array($uploads) ? $uploads : []);
        if ($uploads !== []) {
            $items = [];
            foreach ($uploads as $upload) {
                if (! $upload instanceof UploadedFile || ! method_exists($upload, 'temporaryUrl')) {
                    continue;
                }

                try {
                    $url = $upload->temporaryUrl();
                } catch (\Throwable) {
                    $url = null;
                }
                if (! is_string($url) || trim($url) === '') {
                    continue;
                }

                $items[] = new NotificationMedia(
                    type: self::mediaType($upload->getMimeType(), $upload->getClientOriginalName()),
                    url: trim($url),
                    fileName: $upload->getClientOriginalName() ?: null,
                );
            }

            return $items;
        }

        $url = is_string($get('media_url')) ? trim($get('media_url')) : '';
        if ($url === '') {
            return [];
        }

        return [new NotificationMedia(
            type: self::mediaType('', $url),
            url: $url,
            fileName: basename((string) parse_url($url, PHP_URL_PATH)) ?: null,
        )];
    }

    private static function mediaType(string $mime, string $name): string
    {
        $extension = strtolower(pathinfo(parse_url($name, PHP_URL_PATH) ?: $name, PATHINFO_EXTENSION));
        if (str_starts_with(strtolower($mime), 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return 'photo';
        }
        if (strtolower($mime) === 'video/mp4' || $extension === 'mp4') {
            return 'video';
        }

        return 'document';
    }
}
