<?php

namespace App\Filament\Resources\NotificationTemplates\Schemas;

use App\Filament\Support\RichTextEditor;
use App\Filament\Support\TelegramPreviewAction;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateRenderer;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

final class NotificationTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основная информация')
                    ->description('Шаблон хранит только текст и подстановочные данные. Фото и видео настраиваются отдельно в рассылке или авто-сообщении.')
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

                Section::make('Текст и подстановочные данные')
                    ->description('Вы можете использовать данные из CRM. При отправке система подставит реальные значения.')
                    ->schema([
                        TextInput::make('subject')
                            ->label('Тема')
                            ->maxLength(255)
                            ->helperText('Необязательно для мессенджеров. Можно использовать подстановочные данные.')
                            ->columnSpanFull(),
                        RichTextEditor::make('body', fn (Get $get): array => ScenarioTemplateVariableCatalog::labelsForPurpose($get('purpose')))
                            ->label('Текст сообщения')
                            ->required()
                            ->maxLength(100000)
                            ->helperText(fn (Get $get): string => $get('purpose') === ScenarioRulePurpose::Marketing->value
                                ? 'Для рассылки доступны имя и язык клиента. Нажмите «Добавить данные» в редакторе или введите {{ client.full_name }}.'
                                : 'Нажмите «Добавить данные» в редакторе, чтобы вставить поле в место курсора. Пример: «Здравствуйте, {{ client.full_name }}! Напоминаем о записи {{ booking.starts_at }}.»')
                            ->columnSpanFull(),
                        Actions::make([
                            TelegramPreviewAction::make(fn (Get $get, ?Model $record): NotificationMessage => self::previewMessage($get, $record)),
                        ])->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
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
        ]);
        $locale = (string) ($get('locale') ?: 'ru');
        $rendered = app(NotificationTemplateRenderer::class)->render(
            $template,
            ['client' => ['full_name' => 'Aikhana', 'language' => $locale]],
            $locale,
        );

        return new NotificationMessage(
            recipientExternalId: 'preview',
            body: $rendered->body,
            subject: $rendered->subject,
            locale: $locale,
            idempotencyKey: 'template-preview',
            mode: NotificationMessageMode::Text,
        );
    }
}
