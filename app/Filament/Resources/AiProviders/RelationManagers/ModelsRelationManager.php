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
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\ModelDiscovery\AiModelDiscoveryService;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ModelsRelationManager extends RelationManager
{
    protected static string $relationship = 'models';

    protected static ?string $title = 'Модели провайдера';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedRectangleStack;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof AiProviderConfiguration
            && ! AiProviderCatalog::isSpecialized($ownerRecord->provider_name)
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

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
                        null,
                        self::discoveredDefinitions($provider, $record),
                    ))
                    ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => $record === null
                        ? (string) ($state ?? '')
                        : self::selection($provider, $record->model_name, $record))
                    ->afterStateUpdated(function (Set $set, Get $get, mixed $state, ?AiModelConfiguration $record) use ($provider): void {
                        $definition = self::definition($provider, $state, $record);
                        if ($definition !== null) {
                            if (blank($get('display_name'))) {
                                $set('display_name', $definition->displayName);
                            }

                            $set('model_modalities', []);
                            if ($definition->pricing !== null) {
                                self::fillCatalogPricing($set, $definition);
                            } else {
                                self::clearPricingFields($set);
                            }
                        } elseif ($state === AiModelCatalog::CUSTOM_MODEL) {
                            self::clearCatalogPricing($set);
                        }
                    })
                    ->live()
                    ->native(false)
                    ->searchable()
                    ->optionsLimit(250)
                    ->required(fn (Get $get): bool => blank($get('model_name'))),
                TextInput::make('model_name')
                    ->label('Название модели в API')
                    ->helperText('Расширенный режим: используйте только для новой, частной или нестандартной модели.')
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
                    ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/'),
                TextInput::make('output_cost_per_million')
                    ->label('Ответ модели')
                    ->prefix('$')
                    ->suffix('/ 1 млн токенов')
                    ->helperText('Если стоимость неизвестна, она не считается нулевой — настройте её ниже.')
                    ->inputMode('decimal')
                    ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'output'))
                    ->nullable()
                    ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/'),
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
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/'),
                        TextInput::make('cache_write_input_cost_per_million')
                            ->label('Запись в кеш')
                            ->prefix('$')
                            ->suffix('/ 1 млн токенов')
                            ->inputMode('decimal')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'cache_write'))
                            ->nullable()
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/'),
                        TextInput::make('reasoning_cost_per_million')
                            ->label('Дополнительное рассуждение')
                            ->prefix('$')
                            ->suffix('/ 1 млн токенов')
                            ->inputMode('decimal')
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): string => self::moneyState($state, $record, 'reasoning'))
                            ->nullable()
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/'),
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
                            ->regex('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/'),
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
                            ->formatStateUsing(fn (mixed $state, ?AiModelConfiguration $record): array => $record === null
                                ? (is_array($state) ? $state : [])
                                : array_values(array_intersect(
                                    $record->capabilities,
                                    array_map(fn (AiModelModality $modality): string => $modality->value, AiModelModality::cases()),
                                )))
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

                        return '$'.AiMoney::displayDecimalFromRateUnits($pricing->inputRatePerMillionUnits())
                            .' / $'.AiMoney::displayDecimalFromRateUnits($pricing->outputRatePerMillionUnits());
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

    private static function definition(
        AiProviderConfiguration $provider,
        mixed $selection,
        ?AiModelConfiguration $record = null,
    ): ?AiModelDefinition {
        try {
            $definition = app(AiModelDiscoveryService::class)->definitionFor($provider, $selection);
            if ($definition !== null) {
                return $definition;
            }
        } catch (\InvalidArgumentException) {
        }

        return self::persistedDefinition($provider, $selection, $record);
    }

    /** @return list<AiModelDefinition> */
    private static function discoveredDefinitions(
        AiProviderConfiguration $provider,
        ?AiModelConfiguration $record = null,
    ): array {
        try {
            $definitions = app(AiModelDiscoveryService::class)->definitionsFor($provider);
        } catch (\Throwable) {
            $definitions = [];
        }

        $persisted = self::persistedDefinition($provider, $record?->model_name, $record);
        if ($persisted !== null && ! collect($definitions)->contains(
            static fn (AiModelDefinition $definition): bool => $definition->modelName === $persisted->modelName,
        )) {
            $definitions[] = $persisted;
        }

        return $definitions;
    }

    private static function selection(
        AiProviderConfiguration $provider,
        string $model,
        ?AiModelConfiguration $record = null,
    ): string {
        return self::definition($provider, $model, $record) instanceof AiModelDefinition
            ? $model
            : AiModelCatalog::CUSTOM_MODEL;
    }

    private static function supportedInputs(
        AiProviderConfiguration $provider,
        mixed $selection,
        ?AiModelConfiguration $record,
    ): string {
        $definition = self::definition($provider, $selection, $record);
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
        $definition = self::definition($provider, $selection, $record);
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

    private static function persistedDefinition(
        AiProviderConfiguration $provider,
        mixed $selection,
        ?AiModelConfiguration $record,
    ): ?AiModelDefinition {
        if (! $record instanceof AiModelConfiguration
            || ! is_string($selection)
            || trim($selection) === ''
            || trim($selection) !== $record->model_name) {
            return null;
        }

        $pricing = $record->getPricingSnapshot();
        if ($pricing->pricingSource !== AiPricingSnapshot::SOURCE_CATALOG
            || ! AiModelCatalog::isImmutableDiscoveredPricing($provider->provider_name, $pricing)
            || AiModelCatalog::find($provider->provider_name, $record->model_name) !== null) {
            return null;
        }

        $modalities = array_values(array_intersect(
            $record->capabilities,
            array_map(
                static fn (AiModelModality $modality): string => $modality->value,
                AiModelModality::cases(),
            ),
        ));

        return AiModelDefinition::fromArray([
            'provider' => $provider->provider_name,
            'model' => $record->model_name,
            'display_name' => $record->display_name,
            'family' => 'Сохранённая модель',
            'summary' => 'Модель сохранена из подключённого каталога; её текущая запись провайдера временно недоступна.',
            'positioning' => 'Ранее обнаруженная',
            'supported_capabilities' => ['text_generation'],
            'modalities' => $modalities,
            'pricing' => $pricing->toArray(),
            'lifecycle' => 'active',
            'catalog_source' => $pricing->catalogSource,
            'pricing_as_of' => $pricing->catalogPricingAsOf,
        ]);
    }

    private static function fillCatalogPricing(Set $set, AiModelDefinition $definition): void
    {
        $pricing = $definition->pricing;
        if ($pricing === null) {
            return;
        }

        $set('input_cost_per_million', AiMoney::displayDecimalFromRateUnits($pricing->inputRatePerMillionUnits()));
        $set('output_cost_per_million', AiMoney::displayDecimalFromRateUnits($pricing->outputRatePerMillionUnits()));
        $set('cache_read_input_cost_per_million', self::decimalOrNull($pricing->cacheReadRatePerMillionUnits()));
        $set('cache_write_input_cost_per_million', self::decimalOrNull($pricing->cacheWriteRatePerMillionUnits()));
        $set('reasoning_cost_per_million', self::decimalOrNull($pricing->reasoningRatePerMillionUnits()));
        $set('fixed_request_cost_applicable', $pricing->fixedRequestCostApplicable);
        $set('fixed_request_cost_minor_units', self::decimalOrNull($pricing->fixedRequestRateUnits()));
        $set('unsupported_meters', implode(', ', $pricing->unsupportedMeters));
    }

    private static function clearPricingFields(Set $set): void
    {
        foreach ([
            'input_cost_per_million',
            'output_cost_per_million',
            'cache_read_input_cost_per_million',
            'cache_write_input_cost_per_million',
            'reasoning_cost_per_million',
            'fixed_request_cost_minor_units',
            'unsupported_meters',
        ] as $field) {
            $set($field, null);
        }

        $set('fixed_request_cost_applicable', false);
    }

    private static function clearCatalogPricing(Set $set): void
    {
        foreach ([
            'model_name',
            'input_cost_per_million',
            'output_cost_per_million',
            'cache_read_input_cost_per_million',
            'cache_write_input_cost_per_million',
            'reasoning_cost_per_million',
            'fixed_request_cost_minor_units',
            'unsupported_meters',
        ] as $field) {
            $set($field, null);
        }

        $set('fixed_request_cost_applicable', false);
        $set('model_modalities', []);
    }

    private static function decimalOrNull(?int $rateUnits): ?string
    {
        return $rateUnits === null ? null : AiMoney::displayDecimalFromRateUnits($rateUnits);
    }

    private static function moneyState(mixed $state, ?AiModelConfiguration $record, string $field): string
    {
        if ($record !== null) {
            $pricing = $record->getPricingSnapshot();
            if ($pricing->pricingSource === AiPricingSnapshot::SOURCE_UNKNOWN) {
                return '';
            }

            $rateUnits = match ($field) {
                'input' => $pricing->inputRatePerMillionUnits(),
                'output' => $pricing->outputRatePerMillionUnits(),
                'cache_read' => $pricing->cacheReadRatePerMillionUnits(),
                'cache_write' => $pricing->cacheWriteRatePerMillionUnits(),
                'reasoning' => $pricing->reasoningRatePerMillionUnits(),
                'fixed' => $pricing->fixedRequestRateUnits(),
                default => null,
            };

            return self::decimalOrNull($rateUnits) ?? '';
        }

        return $state === null || $state === '' ? '' : (string) $state;
    }
}
