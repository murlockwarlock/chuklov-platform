<?php

namespace App\Filament\Resources\NotificationTemplates\Schemas;

use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

final class NotificationTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основная информация')
                    ->description('Шаблон — это текст сообщения. Когда, кому и через какой канал его отправлять, настраивается отдельно в разделе «Правила сообщений».')
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
                            ->label('Назначение сообщения')
                            ->options([
                                ScenarioRulePurpose::Service->value => 'Сервисное сообщение',
                                ScenarioRulePurpose::Transactional->value => 'Системное сообщение',
                                ScenarioRulePurpose::Marketing->value => 'Маркетинговая рассылка',
                            ])
                            ->helperText('Категория сообщения. Сама по себе не определяет получателя, время отправки или канал связи — они настраиваются в правиле.')
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
                        Textarea::make('body')
                            ->label('Текст сообщения')
                            ->required()
                            ->rows(10)
                            ->maxLength(100000)
                            ->helperText('Пример: «Здравствуйте, {{ client.full_name }}! Напоминаем о записи {{ booking.starts_at }}.»')
                            ->columnSpanFull(),
                        Select::make('insert_variable')
                            ->label('Добавить данные')
                            ->options(ScenarioTemplateVariableCatalog::labels())
                            ->placeholder('Выберите данные для вставки')
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if ($state === null || ! in_array($state, ScenarioTemplateVariableCatalog::allowed(), true)) {
                                    $set('insert_variable', null);

                                    return;
                                }

                                $body = trim((string) $get('body'));
                                $token = '{{ '.$state.' }}';
                                $set('body', $body === '' ? $token : $body."\n".$token);
                                $set('insert_variable', null);
                            })
                            ->helperText('Система автоматически подставит реальные данные при отправке (например, имя клиента или дату визита).')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
