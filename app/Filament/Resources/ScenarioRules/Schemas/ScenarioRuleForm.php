<?php

namespace App\Filament\Resources\ScenarioRules\Schemas;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class ScenarioRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название правила')
                    ->required()
                    ->maxLength(160),
                Select::make('trigger_event')
                    ->label('Когда')
                    ->options([
                        ScenarioEventType::BookingCompleted->value => 'Клиент завершил визит',
                    ])
                    ->required(),
                Toggle::make('is_enabled')
                    ->label('Активно')
                    ->required()
                    ->default(false),
                TextInput::make('delay_value')
                    ->label('Через')
                    ->integer()
                    ->required()
                    ->minValue(0)
                    ->maxValue(PHP_INT_MAX),
                Select::make('delay_unit')
                    ->label('Единица времени')
                    ->options([
                        ScenarioDelayUnit::Minutes->value => 'минут',
                        ScenarioDelayUnit::Hours->value => 'часов',
                        ScenarioDelayUnit::Days->value => 'дней',
                    ])
                    ->required(),
                Select::make('purpose')
                    ->label('Назначение сообщения')
                    ->options([
                        ScenarioRulePurpose::Service->value => 'Сервисное сообщение',
                        ScenarioRulePurpose::Transactional->value => 'Системное сообщение',
                    ])
                    ->required(),
                Select::make('template_version_id')
                    ->label('Сообщение')
                    ->options(fn (): array => NotificationTemplateVersion::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->where('status', NotificationTemplateStatus::Published->value)
                        ->with('template')
                        ->orderByDesc('id')
                        ->get()
                        ->mapWithKeys(function (NotificationTemplateVersion $version): array {
                            $template = $version->template;

                            return [
                                $version->getKey() => ($template?->name ?: 'Сообщение').' — '.self::localeLabel($template?->locale),
                            ];
                        })
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Для уже отправленных сообщений сохраняется выбранный текст.'),
                Repeater::make('conditions')
                    ->label('Дополнительное условие')
                    ->schema([
                        Select::make('type')
                            ->label('Что проверить')
                            ->options([
                                'booking.status' => 'Статус записи',
                                'client.language' => 'Язык клиента',
                            ])
                            ->required(),
                        Select::make('operator')
                            ->label('Проверка')
                            ->options([
                                ScenarioConditionOperator::Equals->value => 'Равно',
                                ScenarioConditionOperator::NotEquals->value => 'Не равно',
                                ScenarioConditionOperator::In->value => 'Одно из',
                                ScenarioConditionOperator::Exists->value => 'Заполнено',
                            ])
                            ->required(),
                        Select::make('value')
                            ->label('Значение')
                            ->options(fn (Get $get): array => self::conditionValues($get('type')))
                            ->searchable()
                            ->visible(fn (Get $get): bool => ! in_array($get('operator'), [
                                ScenarioConditionOperator::In->value,
                                ScenarioConditionOperator::Exists->value,
                            ], true))
                            ->required(fn (Get $get): bool => in_array($get('operator'), [
                                ScenarioConditionOperator::Equals->value,
                                ScenarioConditionOperator::NotEquals->value,
                            ], true)),
                        TagsInput::make('value')
                            ->label('Значения')
                            ->visible(fn (Get $get): bool => $get('operator') === ScenarioConditionOperator::In->value)
                            ->required(fn (Get $get): bool => $get('operator') === ScenarioConditionOperator::In->value)
                            ->nestedRecursiveRules(['string', 'max:120']),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->addActionLabel('Добавить условие')
                    ->columnSpanFull(),
                Select::make('recipient_strategy.type')
                    ->label('Кому')
                    ->options([
                        'client' => 'Клиенту записи',
                        'members' => 'Выбранным сотрудникам',
                        'roles' => 'Сотрудникам по роли',
                    ])
                    ->required()
                    ->default('client')
                    ->live(),
                Select::make('recipient_strategy.user_ids')
                    ->label('Сотрудники')
                    ->options(fn (): array => OrganizationMembership::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->active()
                        ->with('user')
                        ->orderBy('user_id')
                        ->get()
                        ->mapWithKeys(fn (OrganizationMembership $membership): array => [
                            $membership->user_id => $membership->user?->name.' ('.$membership->user?->email.')',
                        ])
                        ->all())
                    ->multiple()
                    ->searchable()
                    ->required(fn (Get $get): bool => $get('recipient_strategy.type') === 'members')
                    ->visible(fn (Get $get): bool => $get('recipient_strategy.type') === 'members'),
                Select::make('recipient_strategy.roles')
                    ->label('Роли')
                    ->options([
                        OrganizationRole::Owner->value => 'Владелец',
                        OrganizationRole::Administrator->value => 'Администратор',
                        OrganizationRole::Staff->value => 'Сотрудник',
                    ])
                    ->multiple()
                    ->required(fn (Get $get): bool => $get('recipient_strategy.type') === 'roles')
                    ->visible(fn (Get $get): bool => $get('recipient_strategy.type') === 'roles'),
                Select::make('channel_priority')
                    ->label('Способ связи')
                    ->options([
                        'telegram' => 'Telegram',
                    ])
                    ->multiple()
                    ->required()
                    ->default(['telegram']),
            ]);
    }

    /** @return array<string, string> */
    private static function conditionValues(mixed $type): array
    {
        return match ($type) {
            'booking.status' => [
                'requested' => 'Ожидает подтверждения',
                'pending_review' => 'На рассмотрении',
                'confirmed' => 'Подтверждена',
                'rejected' => 'Отклонена',
                'cancelled' => 'Отменена',
                'completed' => 'Завершена',
                'no_show' => 'Не состоялась',
            ],
            'client.language' => [
                'ru' => 'Русский',
                'en' => 'Английский',
            ],
            default => [],
        };
    }

    private static function localeLabel(?string $locale): string
    {
        return match ($locale) {
            'ru' => 'Русский',
            'en' => 'Английский',
            default => 'Другой язык',
        };
    }
}
