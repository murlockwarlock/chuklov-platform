<?php

namespace App\Filament\Resources\NotificationTemplates\Schemas;

use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

final class NotificationTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(160),
                Select::make('locale')
                    ->options([
                        'en' => 'Английский',
                        'ru' => 'Русский',
                    ])
                    ->label('Язык')
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                Select::make('purpose')
                    ->label('Назначение сообщения')
                    ->options([
                        ScenarioRulePurpose::Service->value => 'Сервисное сообщение',
                        ScenarioRulePurpose::Transactional->value => 'Системное сообщение',
                    ])
                    ->required(),
                Toggle::make('is_active')
                    ->label('Включён')
                    ->required()
                    ->default(true),
                TextInput::make('subject')
                    ->label('Тема')
                    ->maxLength(255)
                    ->helperText('Необязательно.'),
                Textarea::make('body')
                    ->label('Сообщение')
                    ->required()
                    ->rows(12)
                    ->maxLength(100000)
                    ->helperText('Добавьте нужные данные через поле ниже. Доступен только безопасный список.'),
                Select::make('insert_variable')
                    ->label('Вставить данные в сообщение')
                    ->options(ScenarioTemplateVariableCatalog::labels())
                    ->placeholder('Выберите данные')
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

                        $variables = array_values(array_filter((array) $get('variables'), 'is_string'));

                        if (! in_array($state, $variables, true)) {
                            $set('variables', [...$variables, $state]);
                        }

                        $set('insert_variable', null);
                    })
                    ->helperText('Например: имя клиента, дата записи или специалист.'),
                Select::make('variables')
                    ->label('Данные для вставки')
                    ->options(ScenarioTemplateVariableCatalog::labels())
                    ->multiple()
                    ->searchable()
                    ->required()
                    ->helperText('Например: имя клиента, дата записи, специалист, услуга.'),
            ]);
    }
}
