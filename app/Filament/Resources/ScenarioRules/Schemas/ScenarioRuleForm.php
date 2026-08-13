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
                TextInput::make('rule_key')
                    ->label('Rule key')
                    ->required()
                    ->maxLength(120)
                    ->helperText('Stable technical identity; it cannot be duplicated within this organization.'),
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(160),
                Select::make('trigger_event')
                    ->label('Trigger')
                    ->options([
                        ScenarioEventType::BookingCompleted->value => 'Booking completed',
                    ])
                    ->required(),
                Toggle::make('is_enabled')
                    ->label('Enabled')
                    ->required()
                    ->default(false),
                TextInput::make('delay_value')
                    ->label('Delay')
                    ->integer()
                    ->required()
                    ->minValue(0)
                    ->maxValue(PHP_INT_MAX),
                Select::make('delay_unit')
                    ->label('Delay unit')
                    ->options([
                        ScenarioDelayUnit::Minutes->value => 'Minutes',
                        ScenarioDelayUnit::Hours->value => 'Hours',
                        ScenarioDelayUnit::Days->value => 'Days',
                    ])
                    ->required(),
                Select::make('purpose')
                    ->label('Communication purpose')
                    ->options([
                        ScenarioRulePurpose::Service->value => 'Service',
                        ScenarioRulePurpose::Transactional->value => 'Transactional',
                    ])
                    ->required(),
                Select::make('template_version_id')
                    ->label('Published template version')
                    ->options(fn (): array => NotificationTemplateVersion::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->where('status', NotificationTemplateStatus::Published->value)
                        ->with('template')
                        ->orderByDesc('id')
                        ->get()
                        ->mapWithKeys(fn (NotificationTemplateVersion $version): array => [
                            $version->getKey() => $version->template?->template_key.' / '.$version->template?->locale.' / v'.$version->version,
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Actions keep this exact version after scheduling.'),
                Repeater::make('conditions')
                    ->label('Typed conditions (all must match)')
                    ->schema([
                        Select::make('type')
                            ->options([
                                'booking.status' => 'Booking status',
                                'client.language' => 'Client language',
                            ])
                            ->required(),
                        Select::make('operator')
                            ->options([
                                ScenarioConditionOperator::Equals->value => 'Equals',
                                ScenarioConditionOperator::NotEquals->value => 'Does not equal',
                                ScenarioConditionOperator::In->value => 'Is one of',
                                ScenarioConditionOperator::Exists->value => 'Exists',
                            ])
                            ->required(),
                        TextInput::make('value')
                            ->label('Value')
                            ->maxLength(120)
                            ->visible(fn (Get $get): bool => ! in_array($get('operator'), [
                                ScenarioConditionOperator::In->value,
                                ScenarioConditionOperator::Exists->value,
                            ], true))
                            ->required(fn (Get $get): bool => in_array($get('operator'), [
                                ScenarioConditionOperator::Equals->value,
                                ScenarioConditionOperator::NotEquals->value,
                            ], true)),
                        TagsInput::make('value')
                            ->label('Values')
                            ->visible(fn (Get $get): bool => $get('operator') === ScenarioConditionOperator::In->value)
                            ->required(fn (Get $get): bool => $get('operator') === ScenarioConditionOperator::In->value)
                            ->nestedRecursiveRules(['string', 'max:120']),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->addActionLabel('Add condition')
                    ->columnSpanFull(),
                Select::make('recipient_strategy.type')
                    ->label('Audience')
                    ->options([
                        'client' => 'Booking client',
                        'members' => 'Explicit organization members',
                        'roles' => 'Organization roles',
                    ])
                    ->required()
                    ->default('client')
                    ->live(),
                Select::make('recipient_strategy.user_ids')
                    ->label('Members')
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
                    ->label('Roles')
                    ->options([
                        OrganizationRole::Owner->value => 'Owner',
                        OrganizationRole::Administrator->value => 'Administrator',
                        OrganizationRole::Staff->value => 'Staff',
                    ])
                    ->multiple()
                    ->required(fn (Get $get): bool => $get('recipient_strategy.type') === 'roles')
                    ->visible(fn (Get $get): bool => $get('recipient_strategy.type') === 'roles'),
                Select::make('channel_priority')
                    ->label('Channel priority / fallback order')
                    ->options([
                        'telegram' => 'Telegram',
                    ])
                    ->multiple()
                    ->required()
                    ->default(['telegram'])
                    ->helperText('Candidates are attempted in this order. Retryable failures wait; unavailable channels may fall back.'),
            ]);
    }
}
