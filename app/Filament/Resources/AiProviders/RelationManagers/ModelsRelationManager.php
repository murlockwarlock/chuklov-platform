<?php

namespace App\Filament\Resources\AiProviders\RelationManagers;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\CreateModelConfiguration;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Enums\ModelLifecycleStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiModelCatalog;
use App\Modules\AI\Domain\Registry\AiModelDefinition;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ModelsRelationManager extends RelationManager
{
    protected static string $relationship = 'models';

    protected static ?string $title = 'Модели провайдера';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedRectangleStack;

    public function form(Schema $schema): Schema
    {
        /** @var AiProviderConfiguration $provider */
        $provider = $this->getOwnerRecord();

        return $schema
            ->components([
                Select::make('model_selection')
                    ->label('Модель')
                    ->options(fn (?AiModelConfiguration $record): array => AiModelCatalog::optionsForProvider(
                        $provider->provider_name,
                        $record?->model_name,
                    ))
                    ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => $record === null
                        ? (string) ($state ?? '')
                        : AiModelCatalog::selection($provider->provider_name, $record->model_name))
                    ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($provider): void {
                        $definition = self::definition($provider->provider_name, $state);
                        if ($definition !== null) {
                            if (blank($get('display_name'))) {
                                $set('display_name', $definition->displayName);
                            }

                            self::fillCatalogPricing($set, $definition);
                        } elseif ($state === AiModelCatalog::CUSTOM_MODEL) {
                            $set('model_name', null);
                            $set('input_cost_per_million', null);
                            $set('output_cost_per_million', null);
                        }
                    })
                    ->live()
                    ->native(false)
                    ->searchable()
                    ->required(fn (Get $get): bool => blank($get('model_name'))),
                TextInput::make('model_name')
                    ->label('Название модели в API')
                    ->helperText('Используйте это поле только если нужной модели пока нет в списке.')
                    ->visible(fn (Get $get): bool => self::isCustomSelection($get('model_selection')))
                    ->required(fn (Get $get): bool => self::isCustomSelection($get('model_selection')))
                    ->dehydratedWhenHidden()
                    ->maxLength(120),
                TextInput::make('display_name')
                    ->label('Название в CRM')
                    ->helperText('Так модель будет называться в рабочих настройках Chuklov.')
                    ->required()
                    ->maxLength(120),
                Select::make('failover_priority')
                    ->label('Порядок использования')
                    ->options(fn (?AiModelConfiguration $record): array => self::failoverOptions($record?->failover_priority))
                    ->helperText('Первая модель используется обычно, следующие подключаются при сбое.')
                    ->default(1)
                    ->native(false)
                    ->required(),
                Placeholder::make('supported_inputs')
                    ->label('Поддерживает модель')
                    ->content(fn (Get $get, ?AiModelConfiguration $record): string => self::supportedInputs($provider, $get('model_selection'), $record))
                    ->helperText('Это технические возможности модели из каталога. Ниже отдельно выберите, для каких задач Chuklov её использовать.')
                    ->columnSpanFull(),
                CheckboxList::make('capabilities')
                    ->label('Использовать для')
                    ->options(self::capabilityOptions())
                    ->helperText('Выбор определяет, какие рабочие сценарии могут направлять запросы в эту модель.')
                    ->columns(2)
                    ->columnSpanFull(),
                Placeholder::make('pricing_source')
                    ->label('Стоимость')
                    ->content(fn (Get $get, ?AiModelConfiguration $record): string => self::pricingSource($provider, $get('model_selection'), $record))
                    ->columnSpanFull(),
                TextInput::make('input_cost_per_million')
                    ->label('Входные данные')
                    ->prefix('$')
                    ->suffix('/ 1 млн токенов')
                    ->helperText('Заполняется автоматически, если стоимость есть в каталоге.')
                    ->inputMode('decimal')
                    ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'input'))
                    ->nullable()
                    ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/'),
                TextInput::make('output_cost_per_million')
                    ->label('Ответ модели')
                    ->prefix('$')
                    ->suffix('/ 1 млн токенов')
                    ->helperText('Если стоимость неизвестна, она не считается нулевой — настройте её ниже.')
                    ->inputMode('decimal')
                    ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'output'))
                    ->nullable()
                    ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/'),
                Toggle::make('is_enabled')
                    ->label('Модель включена')
                    ->default(false),
                Section::make('Расширенные настройки стоимости и входных данных')
                    ->description('Используйте этот раздел, если провайдер взимает дополнительную плату или у ручной модели есть особые типы входных данных.')
                    ->collapsed()
                    ->schema([
                        TextInput::make('cache_read_input_cost_per_million')
                            ->label('Чтение из кеша')
                            ->prefix('$')
                            ->suffix('/ 1 млн токенов')
                            ->inputMode('decimal')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'cache_read'))
                            ->nullable()
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/'),
                        TextInput::make('cache_write_input_cost_per_million')
                            ->label('Запись в кеш')
                            ->prefix('$')
                            ->suffix('/ 1 млн токенов')
                            ->inputMode('decimal')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'cache_write'))
                            ->nullable()
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/'),
                        TextInput::make('reasoning_cost_per_million')
                            ->label('Дополнительное рассуждение')
                            ->prefix('$')
                            ->suffix('/ 1 млн токенов')
                            ->inputMode('decimal')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'reasoning'))
                            ->nullable()
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/'),
                        Toggle::make('fixed_request_cost_applicable')
                            ->label('Есть фиксированная стоимость запроса')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): bool => $record === null
                                ? (bool) $state
                                : $record->getPricingSnapshot()->fixedRequestCostApplicable)
                            ->default(false),
                        TextInput::make('fixed_request_cost_minor_units')
                            ->label('Фиксированная стоимость запроса')
                            ->prefix('$')
                            ->inputMode('decimal')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'fixed'))
                            ->nullable()
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/'),
                        TextInput::make('unsupported_meters')
                            ->label('Другие списания')
                            ->helperText('Перечислите через запятую то, что нельзя посчитать в этой настройке. Такую модель нельзя активировать до уточнения стоимости.')
                            ->placeholder('например: дополнительная обработка изображений')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => $record === null
                                ? (string) ($state ?? '')
                                : implode(', ', $record->getPricingSnapshot()->unsupportedMeters)),
                        CheckboxList::make('model_modalities')
                            ->label('Типы входных данных для ручной модели')
                            ->options(collect(AiModelModality::cases())->mapWithKeys(fn (AiModelModality $modality): array => [$modality->value => $modality->label()])->all())
                            ->visible(fn (Get $get): bool => self::isCustomSelection($get('model_selection')))
                            ->helperText('Для модели из каталога эти возможности определяются автоматически.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')->label('Название')->sortable(),
                TextColumn::make('model_name')
                    ->label('Техническое имя')
                    ->formatStateUsing(function (string $state): string {
                        $provider = $this->getOwnerRecord();
                        if (! $provider instanceof AiProviderConfiguration) {
                            return $state;
                        }

                        $definition = AiModelCatalog::find($provider->provider_name, $state);

                        return $definition instanceof AiModelDefinition ? $definition->displayName : $state;
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failover_priority')
                    ->label('Порядок')
                    ->formatStateUsing(fn (mixed $state): string => self::failoverLabel((int) $state))
                    ->sortable(),
                TextColumn::make('lifecycle_status')
                    ->label('Состояние')
                    ->formatStateUsing(fn ($state) => $state instanceof ModelLifecycleStatus ? $state->label() : (string) $state),
                TextColumn::make('is_enabled')->label('Статус')->formatStateUsing(fn ($state) => $state ? 'Включена' : 'Отключена'),
                TextColumn::make('pricing_snapshot')
                    ->label('Стоимость')
                    ->state(function (AiModelConfiguration $record): string {
                        $pricing = $record->getPricingSnapshot();
                        $provider = $this->getOwnerRecord();

                        if ($provider instanceof AiProviderConfiguration
                            && AiModelCatalog::pricingIsStale(
                                $provider->provider_name,
                                $record->model_name,
                                $pricing,
                            )) {
                            return 'Стоимость модели обновилась';
                        }

                        if ($pricing->pricingSource === AiPricingSnapshot::SOURCE_UNKNOWN) {
                            return 'Стоимость не задана';
                        }

                        return '$'.AiMoney::decimalFromMinorUnits($pricing->inputCostPerMillionMinorUnits)
                            .' / $'.AiMoney::decimalFromMinorUnits($pricing->outputCostPerMillionMinorUnits);
                    }),
            ])
            ->emptyStateHeading('Моделей пока нет')
            ->emptyStateDescription('Выберите одну или несколько моделей. Первая используется как основная, остальные — как резервные.')
            ->headerActions([
                CreateAction::make()
                    ->label('Добавить модель')
                    ->using(function (array $data): AiModelConfiguration {
                        $actor = Auth::user();
                        if (! $actor instanceof User) {
                            throw new \LogicException('Authenticated user required.');
                        }
                        /** @var AiProviderConfiguration $provider */
                        $provider = $this->getOwnerRecord();

                        return app(CreateModelConfiguration::class)->handle($actor, $provider, $data);
                    }),
            ])
            ->recordActions([
                Action::make('refresh_pricing')
                    ->label('Применить актуальную стоимость')
                    ->color('warning')
                    ->visible(function (AiModelConfiguration $record): bool {
                        $provider = $this->getOwnerRecord();
                        if (! $provider instanceof AiProviderConfiguration) {
                            return false;
                        }

                        $definition = AiModelCatalog::find($provider->provider_name, $record->model_name);

                        return $definition !== null
                            && $definition->pricing !== null
                            && AiModelCatalog::pricingIsStale(
                                $provider->provider_name,
                                $record->model_name,
                                $record->getPricingSnapshot(),
                            );
                    })
                    ->requiresConfirmation()
                    ->action(function (
                        AiModelConfiguration $record,
                        CreateAndActivateModelRelease $releaseAction,
                    ): void {
                        $actor = Auth::user();
                        if (! $actor instanceof User) {
                            throw new \LogicException('Authenticated user required.');
                        }

                        $releaseAction->handle($actor, $record, [
                            'model_selection' => $record->model_name,
                            'is_enabled' => $record->is_enabled,
                        ]);

                        Notification::make()
                            ->title('Актуальная стоимость применена')
                            ->success()
                            ->send();
                    }),
                EditAction::make()
                    ->using(function (AiModelConfiguration $record, array $data): AiModelConfiguration {
                        $actor = Auth::user();
                        if (! $actor instanceof User) {
                            throw new \LogicException('Authenticated user required.');
                        }

                        app(CreateAndActivateModelRelease::class)->handle($actor, $record, $data);

                        return $record->fresh() ?? $record;
                    }),
            ]);
    }

    /** @return array<int, string> */
    private static function failoverOptions(?int $currentPriority = null): array
    {
        $priorities = range(1, 10);
        if ($currentPriority !== null && $currentPriority > 10) {
            $priorities[] = $currentPriority;
        }

        return array_combine($priorities, array_map(
            static fn (int $priority): string => self::failoverLabel($priority),
            $priorities,
        ));
    }

    private static function failoverLabel(int $priority): string
    {
        return $priority === 1 ? '1 — Основная' : $priority.' — Резервная '.($priority - 1);
    }

    /** @return array<string, string> */
    private static function capabilityOptions(): array
    {
        return collect(AiCapability::cases())
            ->mapWithKeys(fn (AiCapability $capability): array => [$capability->value => $capability->label()])
            ->all();
    }

    private static function isCustomSelection(mixed $selection): bool
    {
        return $selection === AiModelCatalog::CUSTOM_MODEL || $selection === null || $selection === '';
    }

    private static function definition(string $provider, mixed $selection): ?AiModelDefinition
    {
        try {
            return AiModelCatalog::selectedDefinition($provider, $selection);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function supportedInputs(
        AiProviderConfiguration $provider,
        mixed $selection,
        ?AiModelConfiguration $record,
    ): string {
        $definition = self::definition($provider->provider_name, $selection);
        if ($definition === null && $record !== null) {
            $definition = AiModelCatalog::find($provider->provider_name, $record->model_name);
        }

        $inputs = $definition === null ? [] : AiModelCatalog::humanSupportedInputs($definition);

        return $inputs === []
            ? 'Возможности не указаны. Для ручной модели настройте типы входных данных в дополнительных настройках.'
            : implode(' · ', array_map(static fn (string $input): string => '✓ '.$input, $inputs));
    }

    private static function pricingSource(
        AiProviderConfiguration $provider,
        mixed $selection,
        ?AiModelConfiguration $record,
    ): string {
        $definition = self::definition($provider->provider_name, $selection);
        if ($record !== null
            && AiModelCatalog::pricingIsStale(
                $provider->provider_name,
                $record->model_name,
                $record->getPricingSnapshot(),
            )) {
            return 'Стоимость модели обновилась. Примените актуальный тариф.';
        }

        if ($definition !== null) {
            return AiModelCatalog::pricingText($definition->pricing);
        }

        if ($record !== null) {
            $pricing = $record->getPricingSnapshot();

            return match ($pricing->pricingSource) {
                AiPricingSnapshot::SOURCE_CATALOG => 'Стоимость загружена из каталога',
                AiPricingSnapshot::SOURCE_MANUAL => 'Стоимость задана вручную',
                default => 'Стоимость не задана',
            };
        }

        return 'Стоимость не задана';
    }

    private static function fillCatalogPricing(Set $set, AiModelDefinition $definition): void
    {
        $pricing = $definition->pricing;
        if ($pricing === null) {
            return;
        }

        $set('input_cost_per_million', AiMoney::decimalFromMinorUnits($pricing->inputCostPerMillionMinorUnits));
        $set('output_cost_per_million', AiMoney::decimalFromMinorUnits($pricing->outputCostPerMillionMinorUnits));
        $set('cache_read_input_cost_per_million', self::decimalOrNull($pricing->cacheReadInputCostPerMillionMinorUnits));
        $set('cache_write_input_cost_per_million', self::decimalOrNull($pricing->cacheWriteInputCostPerMillionMinorUnits));
        $set('reasoning_cost_per_million', self::decimalOrNull($pricing->reasoningCostPerMillionMinorUnits));
        $set('fixed_request_cost_applicable', $pricing->fixedRequestCostApplicable);
        $set('fixed_request_cost_minor_units', self::decimalOrNull($pricing->fixedRequestCostMinorUnits));
        $set('unsupported_meters', implode(', ', $pricing->unsupportedMeters));
    }

    private static function decimalOrNull(?int $minorUnits): ?string
    {
        return $minorUnits === null ? null : AiMoney::decimalFromMinorUnits($minorUnits);
    }

    private static function moneyState(mixed $state, ?AiModelConfiguration $record, string $field): string
    {
        if ($record !== null) {
            $pricing = $record->getPricingSnapshot();
            if ($pricing->pricingSource === AiPricingSnapshot::SOURCE_UNKNOWN) {
                return '';
            }

            $minorUnits = match ($field) {
                'input' => $pricing->inputCostPerMillionMinorUnits,
                'output' => $pricing->outputCostPerMillionMinorUnits,
                'cache_read' => $pricing->cacheReadInputCostPerMillionMinorUnits,
                'cache_write' => $pricing->cacheWriteInputCostPerMillionMinorUnits,
                'reasoning' => $pricing->reasoningCostPerMillionMinorUnits,
                'fixed' => $pricing->fixedRequestCostMinorUnits,
                default => null,
            };

            return self::decimalOrNull($minorUnits) ?? '';
        }

        return $state === null || $state === '' ? '' : (string) $state;
    }
}
